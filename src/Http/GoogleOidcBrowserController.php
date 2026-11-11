<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Google\GoogleOidcFlow;
use OPGateway\Google\GoogleOidcProvider;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\PasswordSessionStamp;
use OPGateway\Identity\PdoGoogleIdentityStore;
use PDOException;
use RuntimeException;

/**
 * Independent account Google OIDC controller. Never maps accounts by email.
 * New users register with a local password and receive normal approval;
 * linking requires an eligible logged-in user and fresh password verification.
 */
final readonly class GoogleOidcBrowserController
{
    public function __construct(
        private BrowserSession $session,
        private LoginAccountRepository $accounts,
        private GuardedLoginService $reauth,
        private OwnerOverviewProvider $overview,
        private PdoGoogleIdentityStore $identities,
        private PdoGoogleOAuthSettings $settings,
        private GoogleOidcProvider $provider,
        private string $origin,
    ) {
    }

    public static function route(string $path): bool
    {
        return in_array($path, [
            '/account/google', '/account/google/start', '/account/google/callback',
            '/account/google/link', '/account/google/unlink',
        ], true);
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    public function dispatch(
        string $method, string $path, ?string $contentType, ?string $body,
        string $sourceIp, string $requestId, string $rawQuery,
    ): array {
        if ($path === '/account/google' && $method === 'GET') {
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null) {
                return self::redirect('/account/login');
            }
            $email = $this->overview->eligibleEmail($userId);
            if ($email === null) {
                return self::error(403);
            }
            $linked = $this->identities->linked($userId);
            return self::html(AccountViews::google(
                $this->session->csrfToken(), $email,
                $this->settings->configured(), $linked['email'] ?? null,
            ));
        }

        if ($path === '/account/google/start' && $method === 'GET') {
            // This URL starts login only, never implicitly links identities.
            if ($this->session->currentUserId($this->accounts) !== null) {
                return self::redirect('/account/google');
            }
            if (!$this->settings->configured()) {
                return self::googleError('Google sign-in unavailable',
                    'You can still sign in with your email and password.', 503);
            }
            $clientId = $this->settings->summary()['client_id'];
            $flow = GoogleOidcFlow::begin($clientId, $this->origin, 'login', null);
            return self::redirect($flow['url']);
        }

        if ($path === '/account/google/callback' && $method === 'GET') {
            return $this->callback($rawQuery);
        }

        if (!in_array($path, ['/account/google/link', '/account/google/unlink'], true)
            || $method !== 'POST') {
            return self::error(404);
        }

        $userId = $this->session->currentUserId($this->accounts);
        if ($userId === null) {
            return self::error(401);
        }
        if ($contentType === null || preg_match(
            '~^application/x-www-form-urlencoded(?:\\s*;\\s*charset=utf-8)?$~iD',
            $contentType,
        ) !== 1) {
            return self::error(415);
        }
        $form = $body === null ? null : StrictForm::decode(
            $body, ['_csrf', 'current_password'],
        );
        if ($form === null || strlen($form['current_password']) > 1024
            || $form['current_password'] === '') {
            return self::error(400);
        }
        if (!$this->session->csrfValid($form['_csrf'])) {
            return self::error(403);
        }
        $email = $this->overview->eligibleEmail($userId);
        if ($email === null || $this->reauth->authenticate(
            $email, $form['current_password'], $sourceIp, $requestId,
        ) !== $userId) {
            return self::error(403);
        }

        if ($path === '/account/google/unlink') {
            $this->identities->unlink($userId);
            $this->session->rotateCsrf();
            return self::redirect('/account/google');
        }

        if (!$this->settings->configured() || $this->identities->linked($userId) !== null) {
            return self::googleError('Google linking unavailable',
                'This account is already connected or Google sign-in is not configured.', 409);
        }
        $flow = GoogleOidcFlow::begin(
            $this->settings->summary()['client_id'], $this->origin, 'link', $userId,
        );
        $this->session->rotateCsrf();
        return self::redirect($flow['url']);
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    private function callback(string $rawQuery): array
    {
        $params = self::callbackParams($rawQuery);
        if ($params === null || !isset($params['state'])) {
            return self::googleError('Google sign-in unavailable',
                'The sign-in request has expired. Please try again.', 403);
        }
        $pending = GoogleOidcFlow::take($params['state']);
        if ($pending === null || isset($params['error'])
            || !isset($params['code'])) {
            return self::googleError('Google sign-in unavailable',
                'The sign-in request was canceled or expired. Please try again.', 403);
        }

        try {
            $credentials = $this->settings->credentials();
            if ($credentials === null) {
                return self::googleError('Google sign-in unavailable',
                    'Sign in with your account password instead.', 503);
            }
            $google = $this->provider->exchange(
                $params['code'], $credentials['client_id'], $credentials['client_secret'],
                $this->origin, $pending['verifier'], $pending['nonce'],
            );
        } catch (RuntimeException) {
            return self::googleError('Google sign-in unavailable',
                'Google verification could not be completed. Try again later.', 503);
        }

        if ($pending['mode'] === 'link') {
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null || $userId !== $pending['user_id']) {
                return self::googleError('Google connection unavailable',
                    'Please sign in again and retry.', 403);
            }
            // Only connect the same verified mailbox. Never infer identity
            // solely from matching email on unlinked Google sign-in attempts.
            $email = $this->overview->eligibleEmail($userId);
            if ($email === null || !hash_equals(strtolower($email), $google['email'])) {
                return self::googleError('Google account does not match',
                    'Choose the Google account with your verified account email.', 403);
            }
            try {
                $this->identities->link($userId, $google['sub'], $google['email']);
            } catch (PDOException) {
                return self::googleError('Google connection unavailable',
                    'This Google account cannot be connected.', 409);
            }
            $this->session->rotateCsrf();
            return self::redirect('/account/google');
        }

        $account = $this->identities->eligibleBySubject($google['sub']);
        if ($account === null) {
            return self::googleError('Google account not connected',
                'First create and verify an account with a password. After approval, sign in and connect Google in your account settings.', 403);
        }
        $this->session->authenticate(
            $account['id'], PasswordSessionStamp::fromHash($account['password_hash']),
        );
        return self::redirect('/account');
    }

    /** @return array<string,string>|null */
    private static function callbackParams(string $rawQuery): ?array
    {
        if ($rawQuery === '' || strlen($rawQuery) > 4096) {
            return null;
        }
        $result = [];
        foreach (explode('&', $rawQuery) as $part) {
            if (!str_contains($part, '=')) {
                return null;
            }
            [$rawKey, $rawValue] = explode('=', $part, 2);
            if (preg_match('/%(?![0-9a-fA-F]{2})/', $rawKey . $rawValue)) {
                return null;
            }
            $key = urldecode($rawKey);
            if (!in_array($key, ['state', 'code', 'error'], true)) {
                continue;
            }
            if (array_key_exists($key, $result)) {
                return null;
            }
            $value = urldecode($rawValue);
            if ($key === 'state' && preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
                return null;
            }
            if ($key === 'code' && ($value === '' || strlen($value) > 2048
                || preg_match('/[\\x00-\\x20\\x7f]/', $value))) {
                return null;
            }
            if ($key === 'error' && ($value === '' || strlen($value) > 64)) {
                return null;
            }
            $result[$key] = $value;
        }
        if (isset($result['code'], $result['error'])) {
            return null;
        }
        return $result;
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function googleError(string $title, string $message, int $status): array
    {
        return self::html(AccountViews::googleStatus($title, $message), $status);
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function html(string $content, int $status = 200): array
    {
        return ['status' => $status, 'content_type' => 'text/html; charset=utf-8',
            'body' => $content];
    }

    /** @return array{status:int,content_type:string,body:string,location:string} */
    private static function redirect(string $destination): array
    {
        return ['status' => 303, 'content_type' => 'text/plain; charset=utf-8',
            'body' => '', 'location' => $destination];
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        return ['status' => $status, 'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => match ($status) {
                400 => 'invalid_request', 401 => 'unauthorized',
                403 => 'forbidden', 415 => 'unsupported_media_type',
                default => 'not_found',
            }], JSON_THROW_ON_ERROR)];
    }
}
