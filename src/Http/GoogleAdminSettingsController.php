<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Security\PdoSecurityEventRecorder;
use OPGateway\View\TwigRenderer;
use PDO;
use Throwable;

/** Admin-owned Google OAuth credentials, stored in encrypted global settings. */
final readonly class GoogleAdminSettingsController
{
    public function __construct(
        private BrowserSession $session,
        private LoginAccountRepository $accounts,
        private AdministratorAuthorization $admins,
        private GuardedLoginService $login,
        private PdoGoogleOAuthSettings $settings,
        private TwigRenderer $twig,
        private PDO $pdo,
        private string $origin,
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
            return ['status' => 200, 'content_type' => 'text/html; charset=utf-8',
                'body' => $this->twig->render('admin/google.html.twig', [
                    'active_section' => 'google',
                    'section_title' => 'Google sign-in',
                    'administrator_label' => 'Administrator',
                    'csrf_token' => $this->session->csrfToken(),
                    'google' => $this->settings->summary(),
                    'redirect_uri' => $this->origin . '/account/google/callback',
                ])];
        }
        if ($method !== 'POST') {
            return self::error(404);
        }
        if ($contentType === null || preg_match(
            '~^application/x-www-form-urlencoded(?:\\s*;\\s*charset=utf-8)?$~iD',
            $contentType,
        ) !== 1) {
            return self::error(415);
        }
        $form = $body === null ? null : StrictForm::decode($body, [
            '_csrf', 'client_id', 'client_secret', 'enabled', 'current_password',
        ]);
        if ($form === null || strlen($form['current_password']) > 1024
            || $form['current_password'] === ''
            || !in_array($form['enabled'], ['0', '1'], true)) {
            return self::error(400);
        }
        if (!$this->session->csrfValid($form['_csrf'])) {
            return self::error(403);
        }
        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $email = $stmt->fetchColumn();
        if (!is_string($email) || $this->login->authenticate(
            $email, $form['current_password'], $sourceIp, $requestId,
        ) !== $id) {
            return self::error(403);
        }
        $this->pdo->beginTransaction();
        try {
            $this->settings->save(
                $form['client_id'], $form['client_secret'], $form['enabled'] === '1',
            );
            (new PdoSecurityEventRecorder($this->pdo))->record(
                'google_settings_changed', $requestId,
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
        return self::redirect('/admin/settings/login/google');
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
        return ['status' => $status, 'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => match ($status) {
                400 => 'invalid_request', 403 => 'forbidden',
                415 => 'unsupported_media_type', default => 'not_found',
            }], JSON_THROW_ON_ERROR)];
    }
}
