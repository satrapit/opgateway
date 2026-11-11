<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Security\LoginCaptcha;
use OPGateway\View\AdminPageRenderer;

/**
 * HTTPS-only administrator request dispatcher.
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
        private ?AdminOverviewRepository $overview = null,
        private ?AdminReviewService $review = null,
        private ?AdminPageRenderer $pages = null,
        private ?LoginCaptcha $captcha = null,
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
            return $this->pages === null ? self::error(404)
                : self::html($this->pages->login(
                    $this->session->csrfToken(), false,
                    $this->captcha?->challenge() ?? [],
                ));
        }

        if ($path === '/admin/login' && $method === 'POST') {
            $expected = ['_csrf', 'email', 'password',
                ...($this->captcha?->fields() ?? [])];
            $form = $body === null ? null : StrictForm::decode($body, $expected);
            if ($form === null || strlen($form['email']) > 254 || strlen($form['password']) > 1024) {
                return self::error(400);
            }
            if ($this->captcha !== null
                && !$this->captcha->verify($form, $sourceIp)) {
                return $this->pages === null ? self::error(403)
                    : self::html($this->pages->login(
                        $this->session->csrfToken(), true, $this->captcha->challenge(),
                    ), 403);
            }
            if (!$this->session->csrfValid($form['_csrf'])) {
                return self::error(403);
            }
            $result = $this->auth->login(
                $trustedHttps, $method, $contentType,
                $sourceIp, $requestId, $form['_csrf'], $form['email'], $form['password'],
            );
            if ($result['status'] === 200) {
                return self::redirect('/admin');
            }
            return $this->pages === null ? self::error(404)
                : self::html($this->pages->login(
                    $this->session->csrfToken(), true,
                    $this->captcha?->challenge() ?? [],
                ), $result['status']);
        }

        // One Twig renderer for every admin page; the role check is fresh.
        $sections = [
            '/admin/users' => 'users',
            '/admin/owners' => 'owners',
            '/admin/clients' => 'clients',
            '/admin/ips/pending' => 'pending_ips',
            '/admin/ips/approved' => 'approved_ips',
        ];
        if ($method === 'GET' && ($path === '/admin' || isset($sections[$path]))) {
            if ($this->pages === null) {
                return self::error(404);
            }
            $userId = $this->session->currentUserId($this->accounts);
            if ($userId === null) {
                return self::redirect('/admin/login');
            }
            if (!$this->administrators->isAdministrator($userId)) {
                return self::error(403);
            }
            // Authorization before every bounded read of sensitive review data.
            $pending = $this->overview?->pending();
            if ($path !== '/admin') {
                return self::html($this->pages->section(
                    $sections[$path], $this->session->csrfToken(),
                    'Administrator', $pending, $this->review !== null,
                ));
            }
            return self::html($this->pages->dashboard(
                $this->session->csrfToken(), 'Administrator', $pending,
                $this->review !== null,
            ));
        }

        if ($path === '/admin/review' && $method === 'POST') {
            if ($this->review === null) {
                return self::error(404);
            }
            $administratorId = $this->session->currentUserId($this->accounts);
            if ($administratorId === null) {
                return self::error(401);
            }
            if (!$this->administrators->isAdministrator($administratorId)) {
                return self::error(403);
            }
            if ($contentType === null
                || preg_match('/^application\/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$/iD', $contentType) !== 1) {
                return self::error(415);
            }

            $form = $body === null ? null
                : StrictForm::decode($body, ['_csrf', 'action', 'target_id']);
            if ($form === null
                || preg_match('/^[1-9][0-9]{0,18}$/D', $form['target_id']) !== 1
                || filter_var($form['target_id'], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]) === false) {
                return self::error(400);
            }
            if (!$this->session->csrfValid($form['_csrf'])) {
                return self::error(403);
            }

            $result = $this->review->execute(
                $administratorId, $sourceIp, $requestId,
                $form['action'], (int) $form['target_id'],
            );
            return $result['status'] === 200
                ? self::redirect(AdminMutationRedirect::forAction($form['action']))
                : self::json($result['status'], ['error' => $result['code']]);
        }

        if ($path === '/admin/provision' && $method === 'POST') {
            if ($this->review === null) {
                return self::error(404);
            }
            $administratorId = $this->session->currentUserId($this->accounts);
            if ($administratorId === null) {
                return self::error(401);
            }
            if (!$this->administrators->isAdministrator($administratorId)) {
                return self::error(403);
            }
            if ($contentType === null
                || preg_match('/^application\/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$/iD', $contentType) !== 1) {
                return self::error(415);
            }
            $form = $body === null ? null
                : StrictForm::decode($body, ['_csrf', 'action', 'target_id', 'value']);
            if ($form === null
                || preg_match('/^[1-9][0-9]{0,18}$/D', $form['target_id']) !== 1
                || filter_var($form['target_id'], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]) === false) {
                return self::error(400);
            }
            if (!$this->session->csrfValid($form['_csrf'])) {
                return self::error(403);
            }
            $result = $this->review->provision(
                $administratorId, $sourceIp, $requestId,
                $form['action'], (int) $form['target_id'], $form['value'],
            );
            return $result['status'] === 200
                ? self::redirect(AdminMutationRedirect::forAction($form['action']))
                : self::json($result['status'], ['error' => $result['code']]);
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
            415 => 'unsupported_media_type',
            default => 'not_found',
        }]);
    }
}
