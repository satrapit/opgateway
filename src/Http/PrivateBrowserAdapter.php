<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\AdministratorAuthorization;

/**
 * Private browser route dispatcher with IP/HTTPS-gated entrypoint.
 *
 * Caller must derive HTTPS from trusted server configuration, start the
 * session and supply bounded request bytes (never POST superglobals).
 * Mutations require a server-side session CSRF token; HTTP Origin is not
 * authoritative on this shared hosting environment.
 */
final readonly class PrivateBrowserAdapter
{
    public function __construct(
        private BrowserSession $session,
        private BrowserAuthController $auth,
        private LoginAccountRepository $accounts,
        private AdministratorAuthorization $administrators,
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

        if ($path === '/admin/login' && $method === 'GET') {
            return self::html(BrowserViews::login($this->session->csrfToken()));
        }

        if ($path === '/admin/login' && $method === 'POST') {
            $form = $body === null ? null : StrictForm::decode($body, ['_csrf', 'email', 'password']);
            if ($form === null || strlen($form['email']) > 254 || strlen($form['password']) > 1024) {
                return self::error(400);
            }
            $result = $this->auth->login(
                $trustedHttps, $method, $contentType,
                $sourceIp, $requestId, $form['_csrf'], $form['email'], $form['password'],
            );
            if ($result['status'] === 200) {
                return self::redirect('/admin');
            }
            return self::html(BrowserViews::login($this->session->csrfToken(), true), $result['status']);
        }

        if ($path === '/admin' && $method === 'GET') {
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null) {
                return self::redirect('/admin/login');
            }
            if (!$this->administrators->isAdministrator($userId)) {
                return self::error(403);
            }
            return self::html(BrowserViews::dashboard($this->session->csrfToken(), 'Administrator'));
        }

        if ($path === '/admin/logout' && $method === 'POST') {
            $form = $body === null ? null : StrictForm::decode($body, ['_csrf']);
            if ($form === null) {
                return self::error(400);
            }
            $result = $this->auth->logout(
                $trustedHttps, $method, $contentType, $form['_csrf'],
            );
            return $result['status'] === 200
                ? self::redirect('/admin/login')
                : self::json($result['status'], $result['body']);
        }

        // Unknown paths and unsupported methods never reveal private state.
        return self::error(404);
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function html(string $html, int $status = 200): array
    {
        return ['status' => $status, 'content_type' => 'text/html; charset=utf-8', 'body' => $html];
    }

    /** @return array{status:int,content_type:string,body:string,location:string} */
    private static function redirect(string $location): array
    {
        // Callers pass only fixed internal route literals.
        return ['status' => 303, 'content_type' => 'text/plain; charset=utf-8',
            'body' => '', 'location' => $location];
    }

    /** @param array<string,string> $payload
     *  @return array{status:int,content_type:string,body:string}
     */
    private static function json(int $status, array $payload): array
    {
        return [
            'status' => $status,
            'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        return self::json($status, ['error' => match ($status) {
            400 => 'invalid_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            default => 'not_found',
        }]);
    }
}
