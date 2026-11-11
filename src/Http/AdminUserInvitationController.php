<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;
use OPGateway\Identity\AdminUserInvitation;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Mail\PdoSmtpSettings;
use OPGateway\View\TwigRenderer;
use PDO;

/** Dedicated user invitation page: admin role, CSRF and SMTP. */
final readonly class AdminUserInvitationController
{
    public function __construct(
        private BrowserSession $session,
        private LoginAccountRepository $accounts,
        private AdministratorAuthorization $admins,
        private AdminUserInvitation $invitation,
        private PdoSmtpSettings $smtp,
        private TwigRenderer $twig,
        private string $origin,
    ) {
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    public function handle(
        string $method, ?string $contentType, ?string $body,
        string $sourceIp, string $requestId, string $rawQuery,
    ): array {
        $administratorId = $this->session->currentUserId($this->accounts);
        if ($administratorId === null) {
            return self::redirect('/admin/login');
        }
        if (!$this->admins->isAdministrator($administratorId)) {
            return self::error(403);
        }
        if ($method === 'GET') {
            return [
                'status' => 200, 'content_type' => 'text/html; charset=utf-8',
                'body' => $this->twig->render('admin/invite-user.html.twig', [
                    'active_section' => 'users',
                    'section_title' => 'Invite user',
                    'csrf_token' => $this->session->csrfToken(),
                    'administrator_label' => 'Administrator',
                    'smtp_ready' => $this->smtp->configured(),
                    'invitation_sent' => $rawQuery === 'sent=1',
                ]),
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
            ['_csrf', 'email', ]);
        if ($form === null) {
            return self::error(400);
        }
        if (!$this->session->csrfValid($form['_csrf'])) {
            return self::error(403);
        }
        if (!$this->smtp->configured()) {
            return self::error(503);
        }
        try {
            $result = $this->invitation->invite(
                $administratorId, $form['email'], $sourceIp,
                $requestId, $this->origin,
            );
        } catch (InvalidArgumentException) {
            return self::error(400);
        }
        if ($result === 'limited') {
            return self::error(429);
        }
        $this->session->rotateCsrf();
        return self::redirect('/admin/users/invite?sent=1');
    }

    /** @return array{status:int,content_type:string,body:string,location:string} */
    private static function redirect(string $to): array
    {
        return [
            'status' => 303, 'content_type' => 'text/plain; charset=utf-8',
            'body' => '', 'location' => $to,
        ];
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        return [
            'status' => $status, 'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => match ($status) {
                400 => 'invalid_request',
                403 => 'forbidden',
                415 => 'unsupported_media_type',
                429 => 'too_many_requests',
                503 => 'service_unavailable',
                default => 'not_found',
            }], JSON_THROW_ON_ERROR),
        ];
    }
}
