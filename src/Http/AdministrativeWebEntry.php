<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\SchemaReadiness;
use OPGateway\Database\PdoConnectionFactory;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PdoAdministratorAuthorization;
use OPGateway\Identity\PdoLoginAccountRepository;
use OPGateway\Security\PdoLoginAttemptLimiter;
use OPGateway\Security\PdoSecurityEventRecorder;

/**
 * Opt-in admin browser HTTP entry; fail-closed until explicitly enabled.
 *
 * This endpoint only supports sign in, read-only landing page and sign out.
 * It does not expose the admin action dispatcher or provider proxy.
 *
 * Trust: HTTPS and REMOTE_ADDR must be supplied by the actual web server,
 * not X-Forwarded-For or X-Forwarded-Proto request headers.
 */
final readonly class AdministrativeWebEntry
{
    /** @param array<string,string> $config */
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string,mixed> $server Trusted PHP server metadata.
     * @return array{status:int,content_type:string,body:string}
     */
    public function handle(string $method, string $path, array $server): array
    {
        if (($this->config['ADMIN_UI_ENABLED'] ?? '') !== 'true') {
            return self::error(404);
        }

        if (($server['HTTPS'] ?? '') !== 'on'
            || !self::exactIp(
                (string) ($server['REMOTE_ADDR'] ?? ''),
                $this->config['ADMIN_UI_ALLOWED_IP'] ?? '',
            )) {
            return self::error(404);
        }

        $origin = $this->config['APP_URL'] ?? '';
        $key = $this->config['LOGIN_RATE_LIMIT_KEY'] ?? '';
        if (!preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~iD', $origin)
            || !preg_match('/^[0-9a-f]{64}$/D', $key)) {
            return self::error(503);
        }

        $pdo = PdoConnectionFactory::open($this->config);
        $readiness = (new SchemaReadiness($pdo))->inspect(MigrationCatalog::all());
        if (!$readiness['ready']) {
            return self::error(503);
        }

        $session = new BrowserSession();
        $session->start(true);
        $accounts = new PdoLoginAccountRepository($pdo);
        $admins = new PdoAdministratorAuthorization($pdo);
        $auth = new BrowserAuthController(
            $session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                new PdoLoginAttemptLimiter($pdo, hex2bin($key)),
                new PdoSecurityEventRecorder($pdo),
            ),
            $accounts,
            $admins,
            $origin,
        );
        $adapter = new PrivateBrowserAdapter($session, $auth, $accounts, $admins);

        $body = null;
        if ($method === 'POST') {
            $stream = fopen('php://input', 'rb');
            if ($stream === false) {
                return self::error(400);
            }
            try {
                $body = BoundedRequestBody::read($stream, isset($server['CONTENT_LENGTH'])
                    ? (string) $server['CONTENT_LENGTH'] : null);
            } finally {
                fclose($stream);
            }
            if ($body === null) {
                return self::error(413);
            }
        }

        return $adapter->dispatch(
            $method,
            $path,
            true,
            isset($server['HTTP_ORIGIN']) ? (string) $server['HTTP_ORIGIN'] : null,
            isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            bin2hex(random_bytes(16)),
            $body,
        );
    }

    private static function exactIp(string $actual, string $allowed): bool
    {
        $a = @inet_pton($actual);
        $b = @inet_pton($allowed);
        return is_string($a) && is_string($b) && hash_equals($b, $a);
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        return [
            'status' => $status,
            'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => match ($status) {
                404 => 'not_found',
                413 => 'payload_too_large',
                400 => 'invalid_request',
                default => 'service_unavailable',
            }], JSON_THROW_ON_ERROR),
        ];
    }
}
