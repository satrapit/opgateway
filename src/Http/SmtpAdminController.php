<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Mail\PdoSmtpSettings;
use OPGateway\Security\PdoSecurityEventRecorder;
use Throwable;
use OPGateway\View\TwigRenderer;
use InvalidArgumentException;
use PDO;

/**
 * Administrator-only SMTP configuration, with mandatory password recheck.
 * The SMTP password is encrypted in private MySQL and never rendered back.
 * The existing admin role is the project's top-level operator role.
 */
final readonly class SmtpAdminController
{
    public function __construct(
        private BrowserSession $session,
        private LoginAccountRepository $accounts,
        private AdministratorAuthorization $admins,
        private GuardedLoginService $login,
        private PdoSmtpSettings $settings,
        private TwigRenderer $twig,
        private PDO $pdo,
    ) {
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    public function handle(
        string $method, ?string $contentType, ?string $body,
        string $sourceIp, string $requestId,
    ): array {
        $id = $this->session->currentUserId($this->accounts);
        if ($id === null) {
            return self::redirect('/admin/login');
        }
        if (!$this->admins->isAdministrator($id)) {
            return self::error(403);
        }
        if ($method === 'GET') {
            $html = $this->twig->render('admin/smtp.html.twig', [
                'active_section' => 'smtp',
                'section_title' => 'SMTP settings',
                'administrator_label' => 'Administrator',
                'csrf_token' => $this->session->csrfToken(),
                'smtp' => $this->settings->summary(),
            ]);
            return ['status' => 200, 'content_type' => 'text/html; charset=utf-8', 'body' => $html];
        }
        if ($method !== 'POST') {
            return self::error(404);
        }
        if ($contentType === null || preg_match(
            '~^application/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$~iD',
            $contentType,
        ) !== 1) {
            return self::error(415);
        }
        $form = $body === null ? null : StrictForm::decode($body, [
            '_csrf', 'host', 'port', 'encryption', 'username', 'password',
            'from_email', 'from_name', 'current_password',
        ]);
        if ($form === null || !$this->session->csrfValid($form['_csrf'])) {
            return self::error($form === null ? 400 : 403);
        }
        if (strlen($form['password']) > 4096
            || strlen($form['current_password']) < 1
            || strlen($form['current_password']) > 1024) {
            return self::error(400);
        }
        if (!in_array($form['port'], ['465', '587'], true)) {
            return self::error(400);
        }
        $email = $this->pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $email->execute([$id]);
        $accountEmail = $email->fetchColumn();
        if (!is_string($accountEmail)
            || $this->login->authenticate(
                $accountEmail, $form['current_password'], $sourceIp, $requestId,
            ) !== $id) {
            return self::error(403);
        }
        // Password rotation and its safe audit event commit atomically.
        $this->pdo->beginTransaction();
        try {
            $this->settings->save(
                $form['host'], (int) $form['port'], $form['encryption'],
                $form['username'], $form['password'],
                $form['from_email'], $form['from_name'],
            );
            (new PdoSecurityEventRecorder($this->pdo))->record(
                'smtp_settings_changed', $requestId,
            );
            $this->pdo->commit();
        } catch (InvalidArgumentException) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return self::error(400);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->session->rotateCsrf();
        return self::redirect('/admin/settings/smtp');
    }

    /** @return array{status:int,content_type:string,body:string,location:string} */
    private static function redirect(string $path): array
    {
        return [
            'status' => 303, 'content_type' => 'text/plain; charset=utf-8',
            'body' => '', 'location' => $path,
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
                default => 'not_found',
            }], JSON_THROW_ON_ERROR),
        ];
    }
}
