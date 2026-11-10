<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Clients\OwnerIntegrationService;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\SelfServiceRegistration;
use OPGateway\Identity\PasswordResetService;
use InvalidArgumentException;

/**
 * Public onboarding and authenticated owner connection management.
 * Every POST is strictly decoded and CSRF-checked; token confirmation
 * requires an intentional POST, not a crawler's link-prefetch GET.
 */
final readonly class SelfServiceBrowserController
{
    public function __construct(
        private BrowserSession $session,
        private LoginAccountRepository $accounts,
        private ?SelfServiceRegistration $registration,
        private ?OwnerIntegrationService $integration,
        private string $origin,
        private ?GuardedLoginService $reauth = null,
        private ?OwnerOverviewProvider $overview = null,
        private ?PasswordResetService $passwordReset = null,
    ) {
    }

    public static function route(string $path): bool
    {
        return in_array($path, [
            '/account/register', '/account/verify', '/account/verify/resend',
            '/account/forgot-password', '/account/reset-password',
            '/account/provider/save', '/account/clients/create', '/account/ips/request',
        ], true);
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
    public function dispatch(
        string $method, string $path, ?string $contentType, ?string $body,
        string $sourceIp, string $requestId, string $rawQuery,
    ): array {
        if (in_array($path, ['/account/forgot-password', '/account/reset-password'], true)) {
            if ($this->passwordReset === null) {
                return self::error(404);
            }
            if ($method === 'GET') {
                if ($path === '/account/forgot-password') {
                    return self::html(AccountViews::forgotPassword($this->session->csrfToken()));
                }
                $token = preg_match('/^token=([0-9a-fA-F]{64})$/D', $rawQuery, $matches) === 1
                    ? $matches[1] : '';
                // Do not show a writable form for consumed, expired or
                // otherwise ineligible tokens. POST checks again atomically.
                if (!$this->passwordReset->isUsable($token)) {
                    return self::html(AccountViews::resetResult(false));
                }
                return self::html(AccountViews::resetPasswordForm($this->session->csrfToken(), $token));
            }
            if ($method !== 'POST') {
                return self::error(404);
            }
            if (!self::isForm($contentType)) {
                return self::error(415);
            }
            $fields = $path === '/account/forgot-password'
                ? ['_csrf', 'email']
                : ['_csrf', 'token', 'password', 'password_confirmation'];
            $form = $body === null ? null : StrictForm::decode($body, $fields);
            if ($form === null || !$this->session->csrfValid($form['_csrf'])) {
                return self::error($form === null ? 400 : 403);
            }
            if ($path === '/account/forgot-password') {
                $result = $this->passwordReset->request($form['email'], $sourceIp, $this->origin);
                return $result === 'limited'
                    ? self::html(AccountViews::resetRequestLimited(), 429)
                    : self::html(AccountViews::resetRequestAccepted());
            }
            if (!hash_equals($form['password'], $form['password_confirmation'])) {
                return self::error(400);
            }
            try {
                $result = $this->passwordReset->reset($form['token'], $form['password']);
            } catch (InvalidArgumentException) {
                return self::error(400);
            }
            $this->session->rotateCsrf();
            return self::html(AccountViews::resetResult($result));
        }

        if (in_array($path, ['/account/register', '/account/verify', '/account/verify/resend'], true)) {
            if ($this->registration === null) {
                return self::error(404);
            }
            if ($path === '/account/register' && $method === 'GET') {
                return self::html(AccountViews::register($this->session->csrfToken()));
            }
            if ($path === '/account/verify' && $method === 'GET') {
                $token = preg_match('/^token=([0-9a-fA-F]{64})$/D', $rawQuery, $matches) === 1
                    ? $matches[1] : '';
                return self::html(AccountViews::verifyForm($this->session->csrfToken(), $token));
            }
            if ($method !== 'POST') {
                return self::error(404);
            }
            if (!self::isForm($contentType)) {
                return self::error(415);
            }
            $fields = match ($path) {
                '/account/register' => ['_csrf', 'email', 'password', 'password_confirmation'],
                '/account/verify/resend' => ['_csrf', 'email'],
                default => ['_csrf', 'token'],
            };
            $form = $body === null ? null : StrictForm::decode($body, $fields);
            if ($form === null || !$this->session->csrfValid($form['_csrf'])) {
                return self::error($form === null ? 400 : 403);
            }
            if ($path === '/account/verify') {
                return self::html(AccountViews::verified(
                    $this->registration->verify($form['token']),
                ));
            }
            if ($path === '/account/register'
                && (!hash_equals($form['password'], $form['password_confirmation'])
                    || strlen($form['password_confirmation']) > 1024)) {
                return self::error(400);
            }
            try {
                $status = $path === '/account/register'
                    ? $this->registration->register(
                        $form['email'], $form['password'], $sourceIp, $this->origin,
                    )
                    : $this->registration->resend(
                        $form['email'], $sourceIp, $this->origin,
                    );
            } catch (InvalidArgumentException) {
                return self::error(400);
            }
            return $status === 'limited'
                ? self::error(429)
                : self::html(AccountViews::registrationAccepted());
        }

        // Authenticated owner operations always require a valid session.
        if ($this->integration === null || $method !== 'POST') {
            return self::error(404);
        }
        $userId = $this->session->currentUserId($this->accounts);
        if ($userId === null) {
            return self::error(401);
        }
        if (!self::isForm($contentType)) {
            return self::error(415);
        }
        $fields = match ($path) {
            '/account/provider/save' => ['_csrf', 'username', 'password', 'current_password'],
            '/account/clients/create' => ['_csrf', 'label'],
            default => ['_csrf', 'client_id', 'ip'],
        };
        $form = $body === null ? null : StrictForm::decode($body, $fields);
        if ($form === null || !$this->session->csrfValid($form['_csrf'])) {
            return self::error($form === null ? 400 : 403);
        }
        try {
            if ($path === '/account/provider/save') {
                if ($this->reauth === null || $this->overview === null
                    || strlen($form['current_password']) === 0
                    || strlen($form['current_password']) > 1024) {
                    return self::error(403);
                }
                $email = $this->overview->eligibleEmail($userId);
                if ($email === null || $this->reauth->authenticate(
                    $email, $form['current_password'], $sourceIp, $requestId,
                ) !== $userId) {
                    return self::error(403);
                }
                $result = $this->integration->saveAccount(
                    $userId, $form['username'], $form['password'], $sourceIp, $requestId,
                );
            } elseif ($path === '/account/clients/create') {
                $result = $this->integration->createClient(
                    $userId, $form['label'], $sourceIp, $requestId,
                );
            } else {
                $clientId = self::positiveId($form['client_id']);
                $result = $clientId === null ? 'denied'
                    : $this->integration->requestIp(
                        $userId, $clientId, $form['ip'], $sourceIp, $requestId,
                    );
            }
        } catch (InvalidArgumentException) {
            return self::error(400);
        }
        if ($result !== 'ok') {
            return self::error($result === 'limited' ? 429 : 403);
        }
        $this->session->rotateCsrf();
        return [
            'status' => 303,
            'content_type' => 'text/plain; charset=utf-8',
            'body' => '',
            'location' => '/account',
        ];
    }

    private static function positiveId(string $value): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]) === false) {
            return null;
        }
        return (int) $value;
    }

    private static function isForm(?string $contentType): bool
    {
        return is_string($contentType)
            && preg_match('~^application/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$~iD', $contentType) === 1;
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function html(string $html, int $status = 200): array
    {
        return [
            'status' => $status,
            'content_type' => 'text/html; charset=utf-8',
            'body' => $html,
        ];
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        return [
            'status' => $status,
            'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => match ($status) {
                400 => 'invalid_request',
                401 => 'unauthorized',
                403 => 'forbidden',
                415 => 'unsupported_media_type',
                429 => 'too_many_requests',
                default => 'not_found',
            }], JSON_THROW_ON_ERROR),
        ];
    }
}
