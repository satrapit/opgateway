<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Clients\OwnerIntegrationService;
use OPGateway\Security\OwnerCredentialManager;

/**
 * HTTPS-only account surface. Only an approved owner can see their clients.
 *
 * This adapter is separate from the operator-IP-gated /admin interface:
 * account access requires a verified account, secured session and CSRF.
 * API bearer generation also requires a fresh password recheck. Database
 * ownership is enforced again by the credential store on each mutation.
 */
final readonly class AccountBrowserAdapter
{
    public function __construct(
        private BrowserSession $session,
        private GuardedLoginService $login,
        private LoginAccountRepository $accounts,
        private OwnerOverviewProvider $overview,
        private ?OwnerCredentialManager $credentials = null,
        private ?OwnerIntegrationService $integration = null,
        private bool $registrationAvailable = false,
    ) {
    }

    /**
     * @return array{status:int,content_type:string,body:string,location?:string}
     */
    public function dispatch(
        string $method,
        string $path,
        bool $trustedHttps,
        ?string $contentType,
        string $sourceIp,
        string $requestId,
        ?string $body,
    ): array {
        if (!$trustedHttps) {
            return self::error(403);
        }

        if ($path === '/account/login' && $method === 'GET') {
            return self::html(AccountViews::login($this->session->csrfToken(), false, $this->registrationAvailable));
        }

        if ($path === '/account/login' && $method === 'POST') {
            if (!self::isForm($contentType)) {
                return self::error(415);
            }
            $form = $body === null ? null
                : StrictForm::decode($body, ['_csrf', 'email', 'password']);
            if ($form === null || strlen($form['email']) > 254 || strlen($form['password']) > 1024) {
                return self::error(400);
            }
            if (!$this->session->csrfValid($form['_csrf'])) {
                return self::error(403);
            }

            $userId = $this->login->authenticate(
                $form['email'], $form['password'], $sourceIp, $requestId,
            );
            if ($userId === null) {
                return self::html(AccountViews::login($this->session->csrfToken(), true, $this->registrationAvailable), 401);
            }
            $this->session->authenticate($userId);
            return self::redirect('/account');
        }

        if ($path === '/account' && $method === 'GET') {
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null) {
                return self::redirect('/account/login');
            }
            $data = $this->overview->forUser($userId);
            if ($data === null) {
                return self::error(403);
            }
            return self::html(AccountViews::dashboard(
                $this->session->csrfToken(), $data, $this->credentials !== null,
                $this->integration !== null, $this->integration?->summary($userId) ?? [],
            ));
        }

        if ($path === '/account/keys/issue' && $method === 'POST') {
            if ($this->credentials === null) {
                return self::error(404);
            }
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null) {
                return self::error(401);
            }
            if (!self::isForm($contentType)) {
                return self::error(415);
            }
            $form = $body === null ? null
                : StrictForm::decode($body, ['_csrf', 'client_id', 'password']);
            if ($form === null || strlen($form['password']) > 1024) {
                return self::error(400);
            }
            $clientId = self::positiveId($form['client_id']);
            if ($clientId === null) {
                return self::error(400);
            }
            if (!$this->session->csrfValid($form['_csrf'])) {
                return self::error(403);
            }
            // The current password is checked again before minting a bearer
            // credential. An unrelated account's password never authorizes.
            $email = $this->overview->eligibleEmail($userId);
            if ($email === null || $this->login->authenticate(
                $email, $form['password'], $sourceIp, $requestId,
            ) !== $userId) {
                return self::error(403);
            }

            $result = $this->credentials->issue($userId, $clientId, $sourceIp, $requestId);
            if ($result['status'] !== 200) {
                return self::error($result['status']);
            }
            // A refreshed POST with the previous CSRF cannot mint a second
            // credential; no secret is saved in the session or a URL.
            $this->session->rotateCsrf();
            return self::html(AccountViews::issued($result['key']));
        }

        if ($path === '/account/keys/revoke' && $method === 'POST') {
            if ($this->credentials === null) {
                return self::error(404);
            }
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null) {
                return self::error(401);
            }
            if (!self::isForm($contentType)) {
                return self::error(415);
            }
            $form = $body === null ? null
                : StrictForm::decode($body, ['_csrf', 'client_id', 'credential_id']);
            if ($form === null) {
                return self::error(400);
            }
            $clientId = self::positiveId($form['client_id']);
            $credentialId = self::positiveId($form['credential_id']);
            if ($clientId === null || $credentialId === null) {
                return self::error(400);
            }
            if (!$this->session->csrfValid($form['_csrf'])) {
                return self::error(403);
            }
            $result = $this->credentials->revoke(
                $userId, $clientId, $credentialId, $sourceIp, $requestId,
            );
            if ($result['status'] !== 200) {
                return self::error($result['status']);
            }
            $this->session->rotateCsrf();
            return self::redirect('/account');
        }

        if ($path === '/account/logout' && $method === 'POST') {
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null) {
                return self::error(401);
            }
            if (!self::isForm($contentType)) {
                return self::error(415);
            }
            $form = $body === null ? null : StrictForm::decode($body, ['_csrf']);
            if ($form === null || !$this->session->csrfValid($form['_csrf'])) {
                return self::error(403);
            }
            $this->session->logout();
            return self::redirect('/account/login');
        }

        return self::error(404);
    }

    private static function isForm(?string $contentType): bool
    {
        return $contentType !== null
            && preg_match('/^application\/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$/iD', $contentType) === 1;
    }

    /** Require canonical, positive decimal IDs with no coercion. */
    private static function positiveId(string $value): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }

        return (int) $value;
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function html(string $body, int $status = 200): array
    {
        return ['status' => $status, 'content_type' => 'text/html; charset=utf-8', 'body' => $body];
    }

    /** @return array{status:int,content_type:string,body:string,location:string} */
    private static function redirect(string $path): array
    {
        return ['status' => 303, 'content_type' => 'text/plain; charset=utf-8',
            'body' => '', 'location' => $path];
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        $code = match ($status) {
            400 => 'invalid_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            415 => 'unsupported_media_type',
            429 => 'too_many_requests',
            default => 'not_found',
        };

        return ['status' => $status, 'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => $code], JSON_THROW_ON_ERROR)];
    }
}
