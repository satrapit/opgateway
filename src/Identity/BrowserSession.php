<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use RuntimeException;

/**
 * Secure browser session helper, never used for API bearer authentication.
 *
 * Only invoke after HTTPS is verified by a trusted web-server boundary.
 * Shared hosting must configure private PHP session storage and secure cookies.
 */
final class BrowserSession
{
    private const USER_KEY = 'opgateway_user_id';
    private const CSRF_KEY = 'opgateway_csrf';
    private const AUTH_AT_KEY = 'opgateway_auth_at';
    private const STAMP_KEY = 'opgateway_auth_session_stamp';
    private const GOOGLE_GRANTS_KEY = 'opgateway_google_action_grants';
    private const GOOGLE_LINK_KEY = 'opgateway_google_link_grant';
    private const MAX_AGE_SECONDS = 1800;

    /** Start a locked-down server-side session over an explicitly trusted HTTPS request. */
    public function start(bool $verifiedHttps): void
    {
        if (!$verifiedHttps) {
            throw new RuntimeException('HTTPS required for browser sessions.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session already active; cannot enforce cookie policy.');
        }
        if (headers_sent()) {
            throw new RuntimeException('Cannot start a secure session after headers.');
        }

        session_name('OPGATEWAYSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            // Google OIDC returns via a cross-site top-level GET. Lax keeps
            // the same server-side state available without allowing cookies
            // on cross-site POSTs; all mutations still require CSRF tokens.
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        if (!session_start()) {
            throw new RuntimeException('Session could not be started.');
        }
    }

    /** Attach the server-controlled authentication version verified at sign-in. */
    public function authenticate(int $userId, string $verifiedSessionStamp): void
    {
        $this->assertActive();
        if ($userId < 1 || preg_match('/^[0-9a-f]{64}$/D', $verifiedSessionStamp) !== 1
            || !session_regenerate_id(true)) {
            throw new RuntimeException('Unable to establish authenticated session.');
        }

        $_SESSION = [
            self::USER_KEY => $userId,
            self::AUTH_AT_KEY => time(),
            self::STAMP_KEY => $verifiedSessionStamp,
            self::CSRF_KEY => bin2hex(random_bytes(32)),
        ];
    }

    /** Re-evaluate user account eligibility on every protected request. */
    public function currentUserId(LoginAccountRepository $accounts): ?int
    {
        $this->assertActive();
        $userId = $_SESSION[self::USER_KEY] ?? null;
        $authenticatedAt = $_SESSION[self::AUTH_AT_KEY] ?? null;
        $oldStamp = $_SESSION[self::STAMP_KEY] ?? null;
        if (!is_int($userId) || $userId < 1 || !is_int($authenticatedAt)
            || $authenticatedAt > time() || time() - $authenticatedAt >= self::MAX_AGE_SECONDS
            || !is_string($oldStamp) || preg_match('/^[0-9a-f]{64}$/D', $oldStamp) !== 1) {
            $this->clearIdentity();
            return null;
        }

        // This lookup also enforces active, verified, approved account state.
        // Disabling the user or changing an authentication method invalidates
        // every session holding an older server-controlled version.
        $currentStamp = $accounts->currentSessionStamp($userId);
        if (!is_string($currentStamp) || strlen($currentStamp) !== 64
            || !hash_equals($oldStamp, $currentStamp)) {
            $this->clearIdentity();
            return null;
        }

        return $userId;
    }

    /** Record a short-lived, account- and action-bound Google verification. */
    public function grantGoogleAction(int $userId, string $action): void
    {
        $this->assertActive();
        if ($userId < 1 || !in_array($action, ['api_key_issue', 'provider_credentials'], true)) {
            throw new RuntimeException('Invalid Google action verification.');
        }
        $_SESSION[self::GOOGLE_GRANTS_KEY][$action] = [
            'user_id' => $userId,
            'verified_at' => time(),
        ];
    }

    /** Consume a Google verification once, no later than five minutes after it was issued. */
    public function consumeGoogleAction(int $userId, string $action): bool
    {
        $this->assertActive();
        $grant = $_SESSION[self::GOOGLE_GRANTS_KEY][$action] ?? null;
        unset($_SESSION[self::GOOGLE_GRANTS_KEY][$action]);
        if (!is_array($grant) || ($grant['user_id'] ?? null) !== $userId
            || !is_int($grant['verified_at'] ?? null)
            || $grant['verified_at'] > time()
            || time() - $grant['verified_at'] > 300) {
            return false;
        }
        return true;
    }

    /** Authorize one same-origin continuation after password-confirmed linking. */
    public function grantGoogleLink(int $userId): void
    {
        $this->assertActive();
        if ($userId < 1) {
            throw new RuntimeException('Invalid Google link continuation.');
        }
        $_SESSION[self::GOOGLE_LINK_KEY] = [
            'user_id' => $userId,
            'granted_at' => time(),
        ];
    }

    /** Consume the link continuation once and within five minutes. */
    public function consumeGoogleLink(int $userId): bool
    {
        $this->assertActive();
        $grant = $_SESSION[self::GOOGLE_LINK_KEY] ?? null;
        unset($_SESSION[self::GOOGLE_LINK_KEY]);
        if (!is_array($grant) || ($grant['user_id'] ?? null) !== $userId
            || !is_int($grant['granted_at'] ?? null)
            || $grant['granted_at'] > time()
            || time() - $grant['granted_at'] > 300) {
            return false;
        }
        return true;
    }

    /** Must only be rendered into an authenticated same-origin HTML form. */
    public function csrfToken(): string
    {
        $this->assertActive();
        $token = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/D', $token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $token;
        }
        return $token;
    }

    /** Rotate after a successful privileged POST to prevent replay on refresh. */
    public function rotateCsrf(): string
    {
        $this->assertActive();
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::CSRF_KEY] = $token;

        return $token;
    }

    public function csrfValid(string $submitted): bool
    {
        $this->assertActive();
        $expected = $_SESSION[self::CSRF_KEY] ?? null;
        return is_string($expected) && preg_match('/^[0-9a-f]{64}$/D', $expected) === 1
            && strlen($submitted) === 64 && hash_equals($expected, $submitted);
    }

    public function logout(): void
    {
        $this->assertActive();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (!session_destroy()) {
            throw new RuntimeException('Session could not be destroyed.');
        }
    }

    private function clearIdentity(): void
    {
        // An anonymous visitor may already have a CSRF token for the login
        // form. Do not discard it when a protected GET redirects to login.
        // Only a former authenticated session loses its CSRF on revocation.
        $hadIdentity = array_key_exists(self::USER_KEY, $_SESSION)
            || array_key_exists(self::AUTH_AT_KEY, $_SESSION)
            || array_key_exists(self::STAMP_KEY, $_SESSION);
        unset($_SESSION[self::USER_KEY], $_SESSION[self::AUTH_AT_KEY],
            $_SESSION[self::STAMP_KEY], $_SESSION[self::GOOGLE_GRANTS_KEY],
            $_SESSION[self::GOOGLE_LINK_KEY]);
        if ($hadIdentity) {
            unset($_SESSION[self::CSRF_KEY]);
        }
    }

    private function assertActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An active server session is required.');
        }
    }
}
