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
use OPGateway\Identity\PdoLoginAccountRepository;
use OPGateway\Observability\DiagnosticLog;
use OPGateway\Security\OwnerCredentialService;
use OPGateway\Security\PdoApiCredentialStore;
use OPGateway\Security\PdoCredentialActionLimiter;
use OPGateway\Security\PdoLoginAttemptLimiter;
use OPGateway\Security\PdoSecurityEventRecorder;
use PDO;
use Throwable;

/**
 * Opt-in account browser entrypoint, separately gated from /admin.
 *
 * Approved owners may sign in over trusted HTTPS without the administrator's
 * operator-IP restriction. Password login remains IP/identity throttled.
 * Bearer issuance and revocation have another independent default-off flag.
 *
 * Never rely on client-provided forwarding headers for HTTPS or source IP.
 */
final readonly class AccountWebEntry
{
    /** @param array<string,string> $config */
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string,mixed> $server Trusted PHP request metadata.
     * @return array{status:int,content_type:string,body:string,location?:string}
     */
    public function handle(string $method, string $path, array $server): array
    {
        if (($this->config['ACCOUNT_UI_ENABLED'] ?? '') !== 'true') {
            return self::error(404);
        }

        if (($server['HTTPS'] ?? '') !== 'on') {
            return self::error(404);
        }

        $origin = $this->config['APP_URL'] ?? '';
        $secret = $this->config['LOGIN_RATE_LIMIT_KEY'] ?? '';
        if (preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~iD', $origin) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $secret) !== 1) {
            DiagnosticLog::record('account.configuration_invalid', 503);
            return self::error(503);
        }

        // The requested authority must match the configured HTTPS origin.
        // Reject Host-header aliases before entering an authenticated flow.
        $authority = (string) ($server['HTTP_HOST'] ?? '');
        if (!hash_equals(strtolower(substr($origin, strlen('https://'))), strtolower($authority))) {
            return self::error(404);
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            DiagnosticLog::record('account.database_driver_unavailable', 503);
            return self::error(503);
        }

        try {
            $pdo = PdoConnectionFactory::open($this->config);
        } catch (Throwable $exception) {
            DiagnosticLog::record('account.database_connection_failed', 503, $exception, [
                'operation' => 'connect',
            ]);
            return self::error(503);
        }

        try {
            $schema = (new SchemaReadiness($pdo))->inspect(MigrationCatalog::all());
        } catch (Throwable $exception) {
            DiagnosticLog::record('account.schema_inspection_failed', 503, $exception, [
                'operation' => 'migration_check',
            ]);
            return self::error(503);
        }
        if (!$schema['ready']) {
            DiagnosticLog::record('account.schema_incomplete', 503);
            return self::error(503);
        }

        $session = new BrowserSession();
        try {
            $session->start(true);
        } catch (Throwable $exception) {
            DiagnosticLog::record('account.session_start_failed', 503, $exception);
            return self::error(503);
        }

        $accounts = new PdoLoginAccountRepository($pdo);
        $events = new PdoSecurityEventRecorder($pdo);
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            new PdoLoginAttemptLimiter($pdo, hex2bin($secret)),
            $events,
        );

        $credentials = ($this->config['ACCOUNT_KEYS_ENABLED'] ?? '') === 'true'
            ? new OwnerCredentialService(
                $pdo,
                new PdoApiCredentialStore($pdo),
                new PdoCredentialActionLimiter($pdo, hex2bin($secret)),
                $events,
            )
            : null;

        $adapter = new AccountBrowserAdapter(
            $session, $login, $accounts, new OwnerOverviewRepository($pdo), $credentials,
        );

        $body = null;
        if ($method === 'POST') {
            $stream = fopen('php://input', 'rb');
            if ($stream === false) {
                return self::error(400);
            }
            try {
                $body = BoundedRequestBody::read(
                    $stream,
                    isset($server['CONTENT_LENGTH']) ? (string) $server['CONTENT_LENGTH'] : null,
                );
            } finally {
                fclose($stream);
            }
            if ($body === null) {
                return self::error(413);
            }
        }

        return $adapter->dispatch(
            $method, $path, true,
            isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            bin2hex(random_bytes(16)), $body,
        );
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        return [
            'status' => $status,
            'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => match ($status) {
                404 => 'not_found',
                400 => 'invalid_request',
                413 => 'payload_too_large',
                default => 'service_unavailable',
            }], JSON_THROW_ON_ERROR),
        ];
    }
}
