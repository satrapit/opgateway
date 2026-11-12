<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;
use OPGateway\Google\GoogleOidcFlow;
use OPGateway\Google\GoogleOidcProvider;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Identity\AccountSecurityService;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\PdoGoogleIdentityStore;
use OPGateway\Identity\PdoUserProfile;
use OPGateway\Mail\GoogleIdentityConfirmationMailer;
use OPGateway\Mail\SmtpConfigurationException;
use OPGateway\Observability\DiagnosticLog;
use OPGateway\Security\SecurityEventRecorder;
use PDOException;
use RuntimeException;
use Throwable;

/** Google-first onboarding, account-method security and action-bound step-up. */
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
        private ?GoogleIdentityConfirmationMailer $confirmationMailer = null,
        private ?AccountSecurityService $accountSecurity = null,
        private ?SecurityEventRecorder $events = null,
        private ?PdoUserProfile $profiles = null,
    ) {
    }

    public static function route(string $path): bool
    {
        return in_array($path, [
            '/account/security', '/account/security/password',
            '/account/google', '/account/google/start', '/account/google/callback',
            '/account/google/confirm', '/account/google/continue', '/account/google/link',
            '/account/google/unlink', '/account/google/verify',
        ], true);
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    public function dispatch(
        string $method, string $path, ?string $contentType, ?string $body,
        string $sourceIp, string $requestId, string $rawQuery,
    ): array {
        if ($path === '/account/google' && $method === 'GET') {
            return self::redirect('/account/security');
        }
        if ($path === '/account/security' && $method === 'GET') {
            return $this->securityPage();
        }
        if ($path === '/account/security/password' && $method === 'POST') {
            return $this->passwordChange($contentType, $body, $sourceIp, $requestId);
        }
        if ($path === '/account/google/start' && $method === 'GET') {
            if ($this->session->currentUserId($this->accounts) !== null) {
                return self::redirect('/account/security');
            }
            return $this->begin('login', null, null);
        }
        if ($path === '/account/google/callback' && $method === 'GET') {
            return $this->callback($rawQuery, $requestId);
        }
        if ($path === '/account/google/continue' && $method === 'GET') {
            return $this->continueLink();
        }
        if ($path === '/account/google/confirm') {
            return $this->confirmation($method, $contentType, $body, $rawQuery, $requestId);
        }
        if ($path === '/account/google/verify' && $method === 'POST') {
            return $this->beginVerification($contentType, $body);
        }
        if (in_array($path, ['/account/google/link', '/account/google/unlink'], true)
            && $method === 'POST') {
            return $this->connectionMutation(
                $path, $contentType, $body, $sourceIp, $requestId,
            );
        }
        return self::error(404);
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    private function securityPage(): array
    {
        $userId = $this->session->currentUserId($this->accounts);
        if ($userId === null) {
            return self::redirect('/account/login');
        }
        $email = $this->overview->eligibleEmail($userId);
        $security = $this->identities->securityStatus($userId);
        if ($email === null || $security === null) {
            return self::error(403);
        }
        $linked = $this->identities->linked($userId);
        return self::html(AccountViews::security(
            $this->session->csrfToken(), $email, $security['has_password'],
            $this->settings->configured(), $linked['email'] ?? null,
            $this->profiles?->complete($userId) ?? true,
        ));
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    private function passwordChange(
        ?string $contentType, ?string $body, string $sourceIp, string $requestId,
    ): array {
        $userId = $this->session->currentUserId($this->accounts);
        if ($userId === null) {
            return self::error(401);
        }
        if ($this->accountSecurity === null || !self::isForm($contentType)) {
            return self::error($this->accountSecurity === null ? 503 : 415);
        }
        $form = $body === null ? null : StrictForm::decode($body, [
            '_csrf', 'current_password', 'new_password', 'password_confirmation',
        ]);
        if ($form === null || !$this->session->csrfValid($form['_csrf'])) {
            return self::error($form === null ? 400 : 403);
        }
        if (!hash_equals($form['new_password'], $form['password_confirmation'])) {
            return self::error(400);
        }
        $status = $this->identities->securityStatus($userId);
        if ($status === null) {
            return self::error(403);
        }
        try {
            $result = $status['has_password']
                ? $this->accountSecurity->changePassword(
                    $userId, $form['current_password'], $form['new_password'],
                    $sourceIp, $requestId,
                )
                : $this->accountSecurity->setPassword(
                    $userId, $form['new_password'], $sourceIp, $requestId,
                );
        } catch (InvalidArgumentException) {
            return self::error(400);
        }
        if ($result !== 'ok') {
            return self::error($result === 'limited' ? 429 : 403);
        }
        // The version increment revokes every session. End this one cleanly.
        $this->session->logout();
        return self::redirect('/account/login');
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    private function beginVerification(?string $contentType, ?string $body): array
    {
        $userId = $this->session->currentUserId($this->accounts);
        if ($userId === null) {
            return self::error(401);
        }
        if (!self::isForm($contentType)) {
            return self::error(415);
        }
        $form = $body === null ? null : StrictForm::decode($body, ['_csrf', 'action']);
        if ($form === null || !$this->session->csrfValid($form['_csrf'])) {
            return self::error($form === null ? 400 : 403);
        }
        if (!in_array($form['action'], ['api_key_issue', 'provider_credentials'], true)
            || $this->identities->linked($userId) === null) {
            return self::error(403);
        }
        return $this->begin('verify', $userId, $form['action']);
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    private function connectionMutation(
        string $path, ?string $contentType, ?string $body,
        string $sourceIp, string $requestId,
    ): array {
        $userId = $this->session->currentUserId($this->accounts);
        if ($userId === null) {
            return self::error(401);
        }
        if (!self::isForm($contentType)) {
            return self::error(415);
        }
        $form = $body === null ? null : StrictForm::decode(
            $body, ['_csrf', 'current_password'],
        );
        if ($form === null || strlen($form['current_password']) > 1024) {
            return self::error(400);
        }
        if (!$this->session->csrfValid($form['_csrf'])) {
            return self::error(403);
        }
        $email = $this->overview->eligibleEmail($userId);
        if ($email === null || $form['current_password'] === ''
            || $this->reauth->authenticate(
                $email, $form['current_password'], $sourceIp, $requestId,
            ) !== $userId) {
            return self::error(403);
        }

        if ($path === '/account/google/unlink') {
            if (!$this->identities->unlink($userId)) {
                $this->record('google_unlink_denied', $requestId);
                return self::error(409);
            }
            $this->record('google_unlinked', $requestId);
            $this->session->logout();
            return self::redirect('/account/login');
        }

        if ($this->identities->linked($userId) !== null) {
            return self::error(409);
        }
        $this->session->grantGoogleLink($userId);
        return self::redirect('/account/google/continue');
    }

    /** Break the form-submission redirect chain before leaving the origin. */
    private function continueLink(): array
    {
        $userId = $this->session->currentUserId($this->accounts);
        if ($userId === null || !$this->session->consumeGoogleLink($userId)
            || $this->identities->linked($userId) !== null) {
            return self::error(403);
        }
        if (!$this->settings->configured()) {
            return self::googleError('Google sign-in unavailable',
                'Use your account password or password recovery instead.', 503);
        }
        $flow = GoogleOidcFlow::begin(
            $this->settings->summary()['client_id'], $this->origin,
            'link', $userId, null,
        );
        return self::html(AccountViews::googleContinuation($flow['url']));
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    private function begin(string $mode, ?int $userId, ?string $action): array
    {
        if (!$this->settings->configured()) {
            return self::googleError('Google sign-in unavailable',
                'Use your account password or password recovery instead.', 503);
        }
        $flow = GoogleOidcFlow::begin(
            $this->settings->summary()['client_id'], $this->origin,
            $mode, $userId, $action,
        );
        return self::redirect($flow['url']);
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    private function callback(string $rawQuery, string $requestId): array
    {
        $params = self::callbackParams($rawQuery);
        if ($params === null || !isset($params['state'])) {
            return self::googleError('Google sign-in unavailable',
                'The sign-in request has expired. Please try again.', 403);
        }
        $pending = GoogleOidcFlow::take($params['state']);
        if ($pending === null || isset($params['error']) || !isset($params['code'])) {
            return self::googleError('Google sign-in unavailable',
                'The sign-in request was canceled or expired. Please try again.', 403);
        }
        try {
            $credentials = $this->settings->credentials();
            if ($credentials === null) {
                return self::googleError('Google sign-in unavailable',
                    'Use your account password or password recovery instead.', 503);
            }
            $google = $this->provider->exchange(
                $params['code'], $credentials['client_id'], $credentials['client_secret'],
                $this->origin, $pending['verifier'], $pending['nonce'],
                $pending['mode'] === 'verify',
            );
        } catch (RuntimeException) {
            $this->record('google_login_denied', $requestId);
            return self::googleError('Google sign-in unavailable',
                'Google verification could not be completed. Try again later.', 503);
        }
        if (($google['email_verified'] ?? false) !== true) {
            $this->record('google_login_denied', $requestId);
            return self::googleError('Google sign-in unavailable',
                'Google did not verify the selected email address.', 403);
        }

        if ($pending['mode'] === 'verify') {
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null || $userId !== $pending['user_id']
                || !$this->identities->subjectBelongsToUser($google['sub'], $userId)) {
                return self::googleError('Google verification unavailable',
                    'The selected Google account is not connected to this account.', 403);
            }
            $this->record('google_action_verified', $requestId);
            $this->session->grantGoogleAction($userId, (string) $pending['action']);
            return self::redirect($pending['action'] === 'api_key_issue'
                ? '/account/clients' : '/account/provider');
        }

        if ($pending['mode'] === 'link') {
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null || $userId !== $pending['user_id']) {
                return self::googleError('Google connection unavailable',
                    'Please sign in again and retry.', 403);
            }
            try {
                $stamp = $this->identities->link(
                    $userId, $google['sub'], $google['email'],
                );
            } catch (PDOException|\DomainException) {
                return self::googleError('Google connection unavailable',
                    'This Google account cannot be connected.', 409);
            }
            $this->record('google_linked', $requestId);
            $this->session->authenticate($userId, $stamp);
            return self::redirect('/account/security');
        }

        try {
            $result = $this->identities->loginOrRegister(
                $google['sub'], $google['email'],
                (bool) ($google['email_authoritative'] ?? false),
                is_string($google['given_name'] ?? null) ? $google['given_name'] : null,
                is_string($google['family_name'] ?? null) ? $google['family_name'] : null,
            );
        } catch (Throwable) {
            $this->record('google_login_denied', $requestId);
            return self::googleError('Google sign-in unavailable',
                'The account could not be completed. Try again later.', 503);
        }
        if ($result['status'] === 'authenticated') {
            $this->record('google_login_allowed', $requestId);
            $this->session->authenticate(
                (int) $result['user_id'], (string) $result['session_stamp'],
            );
            return self::redirect('/account');
        }
        if ($result['status'] === 'linked') {
            $this->record('google_linked', $requestId);
            $this->session->authenticate(
                (int) $result['user_id'], (string) $result['session_stamp'],
            );
            return self::redirect('/account');
        }
        if ($result['status'] === 'created') {
            $this->record('google_signup_created', $requestId);
            $this->session->authenticate(
                (int) $result['user_id'], (string) $result['session_stamp'],
            );
            return self::redirect('/account');
        }
        if ($result['status'] === 'pending') {
            $this->record('google_login_denied', $requestId);
            return self::googleError('Account awaiting approval',
                'An administrator must approve access before you can sign in.', 403);
        }
        if ($result['status'] === 'confirmation_required') {
            if ($this->confirmationMailer === null) {
                DiagnosticLog::record(
                    'account.google_confirmation_configuration_failed', 503,
                );
                return self::googleError('Email confirmation unavailable',
                    'The existing account could not be confirmed. Try again later.', 503);
            }
            try {
                $token = $this->identities->issueConfirmation(
                    (int) $result['user_id'], $google['sub'], $google['email'],
                    $pending['transaction_id'],
                );
            } catch (Throwable $error) {
                DiagnosticLog::record(
                    'account.google_confirmation_token_creation_failed', 503, $error,
                );
                return self::googleError('Email confirmation unavailable',
                    'The existing account could not be confirmed. Try again later.', 503);
            }
            try {
                $this->confirmationMailer->sendGoogleIdentityConfirmation(
                    $google['email'], $token, $this->origin,
                );
            } catch (SmtpConfigurationException $error) {
                $this->cancelConfirmation($token);
                DiagnosticLog::record(
                    'account.google_confirmation_configuration_failed', 503, $error,
                );
                return self::googleError('Email confirmation unavailable',
                    'The existing account could not be confirmed. Try again later.', 503);
            } catch (Throwable $error) {
                $this->cancelConfirmation($token);
                DiagnosticLog::record(
                    'account.google_confirmation_delivery_failed', 503, $error,
                );
                return self::googleError('Email confirmation unavailable',
                    'The existing account could not be confirmed. Try again later.', 503);
            }
            try {
                $this->record('google_confirmation_sent', $requestId);
            } catch (Throwable $error) {
                $this->cancelConfirmation($token);
                throw $error;
            }
            return self::googleError('Check your email',
                'An existing account uses that address. Open the one-time confirmation link we sent before Google can be connected.', 200);
        }
        $this->record('google_login_denied', $requestId);
        return self::googleError('Google sign-in unavailable',
            'This account is not eligible to sign in.', 403);
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    private function confirmation(
        string $method, ?string $contentType, ?string $body,
        string $rawQuery, string $requestId,
    ): array {
        if ($method === 'GET') {
            $token = preg_match('/^token=([0-9a-fA-F]{64})$/D', $rawQuery, $matches) === 1
                ? strtolower($matches[1]) : '';
            return self::html(AccountViews::googleConfirmation(
                $this->session->csrfToken(), $token,
            ));
        }
        if ($method !== 'POST' || !self::isForm($contentType)) {
            return self::error($method === 'POST' ? 415 : 404);
        }
        $form = $body === null ? null : StrictForm::decode($body, ['_csrf', 'token']);
        if ($form === null || !$this->session->csrfValid($form['_csrf'])) {
            return self::error($form === null ? 400 : 403);
        }
        $result = $this->identities->confirm($form['token']);
        if ($result['status'] === 'authenticated') {
            $this->record('google_linked', $requestId);
            $this->session->authenticate(
                (int) $result['user_id'], (string) $result['session_stamp'],
            );
            return self::redirect('/account');
        }
        if ($result['status'] === 'pending') {
            $this->record('google_linked', $requestId);
            return self::googleError('Email confirmed',
                'Google is connected. An administrator must approve this account before sign-in.', 403);
        }
        return self::googleError('Confirmation unavailable',
            'The confirmation link is invalid, expired, or already used.', 403);
    }

    private function cancelConfirmation(string $token): void
    {
        try {
            $this->identities->cancelConfirmation($token);
        } catch (Throwable $error) {
            DiagnosticLog::record(
                'account.google_confirmation_cancellation_failed', 503, $error,
            );
        }
    }

    private function record(string $event, string $requestId): void
    {
        $this->events?->record($event, $requestId);
    }

    private static function isForm(?string $contentType): bool
    {
        return is_string($contentType) && preg_match(
            '~^application/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$~iD',
            $contentType,
        ) === 1;
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
                || preg_match('/[\x00-\x20\x7f]/', $value))) {
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
                403 => 'forbidden', 409 => 'conflict', 415 => 'unsupported_media_type',
                429 => 'too_many_requests', 503 => 'service_unavailable',
                default => 'not_found',
            }], JSON_THROW_ON_ERROR)];
    }
}
