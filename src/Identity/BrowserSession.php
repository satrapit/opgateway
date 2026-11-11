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
    private const STAMP_KEY = 'opgateway_password_stamp';
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
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        if (!session_start()) {
            throw new RuntimeException('Session could not be started.');
        }
    }

    /** Attach the fingerprint of the password hash actually verified at sign-in. */
    public function authenticate(int $userId, string $verifiedPasswordStamp): void
    {
        $this->assertActive();
        if ($userId < 1 || preg_match('/^[0-9a-f]{64}$/D', $verifiedPasswordStamp) !== 1
            || !session_regenerate_id(true)) {
            throw new RuntimeException('Unable to establish authenticated session.');
        }

        $_SESSION = [
            self::USER_KEY => $userId,
            self::AUTH_AT_KEY => time(),
            self::STAMP_KEY => $verifiedPasswordStamp,
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
        // Any password hash replacement rejects existing browser sessions.
        $currentStamp = $accounts->currentSessionStamp($userId);
        if (!is_string($currentStamp) || strlen($currentStamp) !== 64
            || !hash_equals($oldStamp, $currentStamp)) {
            $this->clearIdentity();
            return null;
        }

        return $userId;
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
                'samesite' => $params['samesite'] ?? 'Strict',
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
            $_SESSION[self::STAMP_KEY]);
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
