<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Clients\ClientManagementService;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Google\GoogleOidcClient;
use OPGateway\Identity\PdoGoogleIdentityStore;
use OPGateway\Clients\OwnerIntegrationService;
use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Identity\EmailVerificationService;
use OPGateway\Identity\PdoEmailVerificationRepository;
use OPGateway\Identity\PdoUserRepository;
use OPGateway\Identity\RegistrationService;
use OPGateway\Identity\SelfServiceRegistration;
use OPGateway\Identity\PasswordResetService;
use OPGateway\Security\PdoPasswordResetLimiter;
use OPGateway\Mail\PdoSmtpSettings;
use OPGateway\Mail\PhpMailerVerificationMailer;
use OPGateway\Provider\PdoProviderAccount;
use OPGateway\Security\PdoSignupLimiter;
use OPGateway\Security\SealedSecret;
use OPGateway\Security\PdoLoginCaptchaSettings;
use OPGateway\Security\LoginCaptcha;
use OPGateway\Security\RuntimeSecrets;
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
 * Account browser entrypoint. All owners use the same secure login flow.
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
        if (($server['HTTPS'] ?? '') !== 'on') {
            return self::error(404);
        }

        $origin = CanonicalOrigin::fromServer($server);
        if ($origin === null) {
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

        try {
            $root = dirname(__DIR__, 2);
            $secret = RuntimeSecrets::limiterKey($root);
            $vault = new SealedSecret(RuntimeSecrets::key($root));
        } catch (Throwable $exception) {
            DiagnosticLog::record('account.secret_unavailable', 503, $exception);
            return self::error(503);
        }

        $accounts = new PdoLoginAccountRepository($pdo);
        $events = new PdoSecurityEventRecorder($pdo);
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            new PdoLoginAttemptLimiter($pdo, $secret),
            $events,
        );

        $credentials = new OwnerCredentialService(
            $pdo,
            new PdoApiCredentialStore($pdo),
            new PdoCredentialActionLimiter($pdo, $secret),
            $events,
        );

        $registration = null;
        $passwordReset = null;
        $integration = new OwnerIntegrationService(
            $pdo,
            new PdoProviderAccount($pdo, $vault),
            new ClientManagementService($pdo),
            new PdoCredentialActionLimiter($pdo, $secret),
            $events,
        );
        // PHPMailer is a required production dependency, not an optional feature.
        $composerAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($composerAutoload) || is_link($composerAutoload)) {
            DiagnosticLog::record('account.mail_autoload_unavailable', 503);
            return self::error(503);
        }
        require_once $composerAutoload;
        $smtp = new PdoSmtpSettings($pdo, $vault);
        if ($smtp->configured() && class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            $mailer = new PhpMailerVerificationMailer($smtp);
            $passwordReset = new PasswordResetService(
                $pdo, new PasswordHasher(), new PdoPasswordResetLimiter($pdo, $secret), $mailer,
            );
            $registration = new SelfServiceRegistration(
                $pdo,
                new RegistrationService(new PdoUserRepository($pdo), new PasswordHasher()),
                new EmailVerificationService(new PdoEmailVerificationRepository($pdo, true)),
                new PdoSignupLimiter($pdo, $secret),
                $mailer,
            );
        }

        $ownerOverview = new OwnerOverviewRepository($pdo);
        $googleSettings = new PdoGoogleOAuthSettings($pdo, $vault);
        $googleAvailable = $googleSettings->configured();
        $captchaSettings = new PdoLoginCaptchaSettings($pdo, $vault);
        $captcha = new LoginCaptcha($captchaSettings, $pdo, $secret, $origin, 'account');
        $adapter = new AccountBrowserAdapter(
            $session, $login, $accounts, $ownerOverview,
            $credentials, $integration, $registration !== null,
            $googleAvailable, $captcha,
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

        if (GoogleOidcBrowserController::route($path)) {
            $response = (new GoogleOidcBrowserController(
                $session, $accounts, $login, $ownerOverview,
                new PdoGoogleIdentityStore($pdo), $googleSettings,
                new GoogleOidcClient(), $origin,
            ))->dispatch(
                $method, $path,
                isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
                $body, (string) ($server['REMOTE_ADDR'] ?? ''),
                bin2hex(random_bytes(16)), (string) ($server['QUERY_STRING'] ?? ''),
            );
        } elseif (SelfServiceBrowserController::route($path)) {
            if ($registration === null
                && in_array($path, [
                    '/account/register', '/account/verify', '/account/verify/resend',
                ], true)) {
                return self::error(503);
            }
            if ($passwordReset === null && in_array($path, [
                '/account/forgot-password', '/account/reset-password',
            ], true)) {
                return self::error(503);
            }
            $response = (new SelfServiceBrowserController(
                $session, $accounts, $registration, $integration, $origin,
                $login, $ownerOverview, $passwordReset,
            ))->dispatch(
                $method, $path,
                isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
                $body, (string) ($server['REMOTE_ADDR'] ?? ''),
                bin2hex(random_bytes(16)), (string) ($server['QUERY_STRING'] ?? ''),
            );
        } else {
            $response = $adapter->dispatch(
                $method, $path, true,
                isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
                (string) ($server['REMOTE_ADDR'] ?? ''),
                bin2hex(random_bytes(16)), $body,
            );
        }

        // The shared locally bundled TailAdmin/Twig UI uses the same strict
        // Content Security Policy for account and administrator HTML.
        if (str_starts_with($response['content_type'], 'text/html')) {
            $recaptcha = $path === '/account/login'
                && $captchaSettings->summary()['provider'] === 'recaptcha';
            $response['csp'] = "default-src 'none'; style-src 'self'; script-src 'self'"
                . ($recaptcha ? " https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/" : "")
                . "; img-src 'self' data:"
                . ($recaptcha ? " https://www.google.com https://www.gstatic.com" : "")
                . "; font-src 'self'; form-action 'self'; "
                . ($recaptcha ? "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; " : "")
                . "base-uri 'none'; frame-ancestors 'none'; object-src 'none'";
        }
        return $response;
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
