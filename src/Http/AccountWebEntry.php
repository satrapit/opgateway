<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Clients\ClientManagementService;
use OPGateway\Clients\OwnerIntegrationService;
use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Identity\EmailVerificationService;
use OPGateway\Identity\PdoEmailVerificationRepository;
use OPGateway\Identity\PdoUserRepository;
use OPGateway\Identity\RegistrationService;
use OPGateway\Identity\SelfServiceRegistration;
use OPGateway\Mail\PdoSmtpSettings;
use OPGateway\Mail\PhpMailerVerificationMailer;
use OPGateway\Provider\PdoProviderAccount;
use OPGateway\Security\PdoSignupLimiter;
use OPGateway\Security\SealedSecret;
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

        $registration = null;
        $integration = null;
        if (($this->config['ACCOUNT_REGISTRATION_ENABLED'] ?? '') === 'true'
            || ($this->config['ACCOUNT_INTEGRATION_ENABLED'] ?? '') === 'true') {
            try {
                $vault = new SealedSecret($this->config['APP_KEY'] ?? '');
            } catch (\InvalidArgumentException) {
                DiagnosticLog::record('account.encryption_key_invalid', 503);
                return self::error(503);
            }
            if (($this->config['ACCOUNT_INTEGRATION_ENABLED'] ?? '') === 'true') {
                $integration = new OwnerIntegrationService(
                    $pdo,
                    new PdoProviderAccount($pdo, $vault),
                    new ClientManagementService($pdo),
                    new PdoCredentialActionLimiter($pdo, hex2bin($secret)),
                    $events,
                );
            }
            if (($this->config['ACCOUNT_REGISTRATION_ENABLED'] ?? '') === 'true') {
                // src/autoload.php intentionally loads only OPGateway classes.
                // The vetted Composer autoloader must be present privately
                // before PHPMailer can be used for public verification.
                $composerAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
                if (!is_file($composerAutoload) || is_link($composerAutoload)) {
                    DiagnosticLog::record('account.mail_autoload_unavailable', 503);
                    return self::error(503);
                }
                require_once $composerAutoload;
                $smtp = new PdoSmtpSettings($pdo, $vault);
                if ($smtp->configured() && class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                    $registration = new SelfServiceRegistration(
                        $pdo,
                        new RegistrationService(new PdoUserRepository($pdo), new PasswordHasher()),
                        new EmailVerificationService(new PdoEmailVerificationRepository($pdo, true)),
                        new PdoSignupLimiter($pdo, hex2bin($secret)),
                        new PhpMailerVerificationMailer($smtp),
                    );
                }
            }
        }

        $adapter = new AccountBrowserAdapter(
            $session, $login, $accounts, new OwnerOverviewRepository($pdo),
            $credentials, $integration, $registration !== null,
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

        if (SelfServiceBrowserController::route($path)) {
            if ($registration === null
                && ($this->config['ACCOUNT_REGISTRATION_ENABLED'] ?? '') === 'true'
                && in_array($path, [
                    '/account/register', '/account/verify', '/account/verify/resend',
                ], true)) {
                return self::error(503);
            }
            return (new SelfServiceBrowserController(
                $session, $accounts, $registration, $integration, $origin,
            ))->dispatch(
                $method, $path,
                isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
                $body, (string) ($server['REMOTE_ADDR'] ?? ''),
                bin2hex(random_bytes(16)), (string) ($server['QUERY_STRING'] ?? ''),
            );
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
