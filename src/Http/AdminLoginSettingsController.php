<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Security\PdoLoginCaptchaSettings;
use OPGateway\Security\PdoSecurityEventRecorder;
use OPGateway\View\TwigRenderer;
use PDO;
use Throwable;

/** Separately routed admin settings screens; writes require administrator session and CSRF. */
final readonly class AdminLoginSettingsController
{
    public function __construct(
        private BrowserSession $session,
        private LoginAccountRepository $accounts,
        private AdministratorAuthorization $admins,
        private PdoLoginCaptchaSettings $captcha,
        private TwigRenderer $twig,
        private PDO $pdo,
    ) {
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    public function handle(
        string $method, string $path, ?string $contentType, ?string $body,
        string $sourceIp, string $requestId,
    ): array {
        $userId = $this->session->currentUserId($this->accounts);
        if ($userId === null) {
            return self::redirect('/admin/login');
        }
        if (!$this->admins->isAdministrator($userId)) {
            return self::error(403);
        }

        if ($method === 'GET') {
            [$file, $active, $title] = match ($path) {
                '/admin/settings/security' => [
                    'admin/captcha.html.twig', 'security', 'Security',
                ],
                default => [null, '', ''],
            };
            if ($file === null) {
                return self::error(404);
            }
            return [
                'status' => 200,
                'content_type' => 'text/html; charset=utf-8',
                'body' => $this->twig->render($file, [
                    'active_section' => $active,
                    'section_title' => $title,
                    'administrator_label' => 'Administrator',
                    'csrf_token' => $this->session->csrfToken(),
                    'captcha' => $this->captcha->summary(),
                ]),
            ];
        }

        if ($method !== 'POST' || $path !== '/admin/settings/security') {
            return self::error(404);
        }
        if ($contentType === null || preg_match(
            '~^application/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$~iD',
            $contentType,
        ) !== 1) {
            return self::error(415);
        }
        $form = $body === null ? null : StrictForm::decode($body, [
            '_csrf', 'provider', 'site_key', 'secret',
        ]);
        if ($form === null) {
            return self::error(400);
        }
        if (!$this->session->csrfValid($form['_csrf'])) {
            return self::error(403);
        }
        $this->pdo->beginTransaction();
        try {
            $this->captcha->save($form['provider'], $form['site_key'], $form['secret']);
            (new PdoSecurityEventRecorder($this->pdo))->record(
                'captcha_settings_changed', $requestId,
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
        return self::redirect('/admin/settings/security');
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
                415 => 'unsupported_media_type', default => 'not_found',
            }], JSON_THROW_ON_ERROR)];
    }
}
