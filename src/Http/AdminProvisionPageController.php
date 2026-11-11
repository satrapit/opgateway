<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\View\TwigRenderer;

/** Full-page creation UI that reuses existing audited provisioning service. */
final readonly class AdminProvisionPageController
{
    public function __construct(
        private BrowserSession $session,
        private LoginAccountRepository $accounts,
        private AdministratorAuthorization $admins,
        private AdminOverviewRepository $overview,
        private AdminReviewService $review,
        private TwigRenderer $twig,
    ) {
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    public function handle(
        string $method, string $path, ?string $contentType, ?string $body,
        string $sourceIp, string $requestId,
    ): array {
        $id = $this->session->currentUserId($this->accounts);
        if ($id === null) {
            return self::redirect('/admin/login');
        }
        if (!$this->admins->isAdministrator($id)) {
            return self::error(403);
        }
        $isClient = $path === '/admin/clients/create';
        if (!$isClient && $path !== '/admin/ips/request') {
            return self::error(404);
        }
        if ($method === 'GET') {
            $list = $this->overview->pending();
            return [
                'status' => 200, 'content_type' => 'text/html; charset=utf-8',
                'body' => $this->twig->render(
                    $isClient ? 'admin/create-client.html.twig'
                        : 'admin/request-ip.html.twig',
                    [
                        'active_section' => $isClient ? 'clients' : 'pending_ips',
                        'section_title' => $isClient ? 'Create API client' : 'Request IP',
                        'administrator_label' => 'Administrator',
                        'csrf_token' => $this->session->csrfToken(),
                        'owners' => $list['owners'], 'clients' => $list['clients'],
                    ],
                ),
            ];
        }
        if ($method !== 'POST') {
            return self::error(404);
        }
        if ($contentType === null
            || preg_match('~^application/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$~iD',
                $contentType) !== 1) {
            return self::error(415);
        }
        $form = $body === null ? null : StrictForm::decode($body,
            ['_csrf', 'target_id', 'value']);
        if ($form === null
            || preg_match('/^[1-9][0-9]{0,18}$/D', $form['target_id']) !== 1
            || filter_var($form['target_id'], FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]) === false) {
            return self::error(400);
        }
        if (!$this->session->csrfValid($form['_csrf'])) {
            return self::error(403);
        }
        $result = $this->review->provision(
            $id, $sourceIp, $requestId,
            $isClient ? 'create_client' : 'request_ip',
            (int) $form['target_id'], $form['value'],
        );
        if ($result['status'] !== 200) {
            return self::error($result['status']);
        }
        $this->session->rotateCsrf();
        return self::redirect($isClient ? '/admin/clients' : '/admin/ips/pending');
    }

    /** @return array{status:int,content_type:string,body:string,location:string} */
    private static function redirect(string $url): array
    {
        return ['status' => 303, 'content_type' => 'text/plain; charset=utf-8',
            'body' => '', 'location' => $url];
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        return ['status' => $status, 'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => match ($status) {
                400 => 'invalid_request', 403 => 'forbidden',
                415 => 'unsupported_media_type',
                429 => 'too_many_requests',
                default => 'not_found',
            }], JSON_THROW_ON_ERROR)];
    }
}
