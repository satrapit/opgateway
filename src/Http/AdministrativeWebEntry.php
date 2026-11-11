<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Mail\PdoSmtpSettings;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Security\SealedSecret;
use OPGateway\Security\PdoLoginCaptchaSettings;
use OPGateway\Security\LoginCaptcha;
use OPGateway\Security\RuntimeSecrets;
use OPGateway\Database\Migrations\SchemaReadiness;
use OPGateway\Database\PdoConnectionFactory;
use OPGateway\Clients\ClientManagementService;
use OPGateway\Clients\AdminClientProvisioningService;
use OPGateway\Identity\AccountApprovalService;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PdoAdministratorAuthorization;
use OPGateway\Identity\PdoLoginAccountRepository;
use OPGateway\Security\PdoAdminActionLimiter;
use OPGateway\Security\PdoLoginAttemptLimiter;
use OPGateway\Security\PdoSecurityEventRecorder;
use OPGateway\Observability\DiagnosticLog;
use Throwable;
use PDO;
use OPGateway\View\TwigRenderer;
use OPGateway\View\TwigAdminPageRenderer;

/**
 * Single administrator browser entrypoint; requires trusted HTTPS and current admin role.
 *
 * All administrator functions use the existing admin role and CSRF checks.
 * No provider proxy is routed through this entrypoint.
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
     * @return array{status:int,content_type:string,body:string,location?:string}
     */
    public function handle(string $method, string $path, array $server): array
    {
        // Public admin login is protected by canonical HTTPS origin,
        // account-password rate limits, secure session and fresh admin role
        // checks. User-selected API client IP whitelists remain enforced.
        if (($server['HTTPS'] ?? '') !== 'on') {
            return self::error(404);
        }

        if (CanonicalOrigin::fromServer($server) === null) {
            return self::error(404);
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            DiagnosticLog::record('admin.database_driver_unavailable', 503);
            return self::error(503);
        }
        try {
            $pdo = PdoConnectionFactory::open($this->config);
        } catch (Throwable $exception) {
            DiagnosticLog::record('admin.database_connection_failed', 503, $exception, [
                'operation' => 'connect',
            ]);
            return self::error(503);
        }
        try {
            $readiness = (new SchemaReadiness($pdo))->inspect(MigrationCatalog::all());
        } catch (Throwable $exception) {
            DiagnosticLog::record('admin.schema_inspection_failed', 503, $exception, [
                'operation' => 'migration_check',
            ]);
            return self::error(503);
        }
        if (!$readiness['ready']) {
            DiagnosticLog::record('admin.schema_incomplete', 503);
            return self::error(503);
        }

        $session = new BrowserSession();
        try {
            $session->start(true);
        } catch (Throwable $exception) {
            DiagnosticLog::record('admin.session_start_failed', 503, $exception);
            return self::error(503);
        }
        try {
            $key = RuntimeSecrets::limiterKey(dirname(__DIR__, 2));
            $vault = new SealedSecret(RuntimeSecrets::key(dirname(__DIR__, 2)));
        } catch (Throwable $exception) {
            DiagnosticLog::record('admin.secret_unavailable', 503, $exception);
            return self::error(503);
        }
        $accounts = new PdoLoginAccountRepository($pdo);
        $admins = new PdoAdministratorAuthorization($pdo);
        $auth = new BrowserAuthController(
            $session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                new PdoLoginAttemptLimiter($pdo, $key),
                new PdoSecurityEventRecorder($pdo),
            ),
            $accounts,
            $admins,
        );
        $review = new AdminReviewService(
            $pdo,
            new AdminActions(
                new AccountApprovalService($pdo),
                new ClientManagementService($pdo),
            ),
            new PdoAdminActionLimiter($pdo, $key),
            new PdoSecurityEventRecorder($pdo),
            new AdminClientProvisioningService($pdo),
        );

        // Twig is the single supported administrator interface.
        {
            // Twig requires the private Composer autoloader and compiled local assets.
            $root = dirname(__DIR__, 2);
            foreach ([
                'vendor/autoload.php',
                'public/assets/admin/app.css',
                'public/assets/admin/app.js',
                'public/assets/admin/theme-init.js',
                'public/assets/admin/icons.svg',
                'public/assets/admin/.htaccess',
            ] as $relative) {
                $file = $root . '/' . $relative;
                if (!is_file($file) || is_link($file)) {
                    DiagnosticLog::record('admin.twig_assets_unavailable', 503);
                    return self::error(503);
                }
            }
        }

        $captchaSettings = new PdoLoginCaptchaSettings($pdo, $vault);
        $captcha = new LoginCaptcha(
            $captchaSettings, $pdo, $key,
            CanonicalOrigin::fromServer($server) ?? '', 'admin',
        );
        $pages = new TwigAdminPageRenderer(
            new TwigRenderer(dirname(__DIR__, 2) . '/templates'),
        );

        $adapter = new PrivateBrowserAdapter(
            $session, $auth, $accounts, $admins,
            new AdminOverviewRepository($pdo), $review, $pages, $captcha,
        );

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

        if ($path === '/admin/captcha/image') {
            $image = $method === 'GET' ? $captcha->image() : null;
            $response = $image === null ? self::error(404) : [
                'status' => 200, 'content_type' => 'image/png', 'body' => $image,
            ];
        } elseif (in_array($path, [
            '/admin/settings', '/admin/settings/login',
            '/admin/settings/login/captcha', '/admin/settings/google',
        ], true) && $method === 'GET') {
            $response = [
                'status' => 303, 'content_type' => 'text/plain; charset=utf-8',
                'body' => '', 'location' => match ($path) {
                    '/admin/settings/google' => '/admin/settings/login/google',
                    default => '/admin/settings/security',
                },
            ];
        } elseif ($path === '/admin/settings/google') {
            // Keep the previous bookmark working without accepting legacy POSTs.
            $response = $method === 'GET'
                ? ['status' => 303, 'content_type' => 'text/plain; charset=utf-8',
                    'body' => '', 'location' => '/admin/settings/login/google']
                : self::error(404);
        } elseif (in_array($path, [
            '/admin/settings', '/admin/settings/login',
            '/admin/settings/security',
        ], true)) {
            $login = new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                new PdoLoginAttemptLimiter($pdo, $key),
                new PdoSecurityEventRecorder($pdo),
            );
            $response = (new AdminLoginSettingsController(
                $session, $accounts, $admins, $login, $captchaSettings,
                new PdoGoogleOAuthSettings($pdo, $vault),
                new TwigRenderer(dirname(__DIR__, 2) . '/templates'), $pdo,
            ))->handle(
                $method, $path,
                isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
                $body, (string) ($server['REMOTE_ADDR'] ?? ''),
                bin2hex(random_bytes(16)),
            );
        } elseif ($path === '/admin/settings/login/google') {
            $login = new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                new PdoLoginAttemptLimiter($pdo, $key),
                new PdoSecurityEventRecorder($pdo),
            );
            $response = (new GoogleAdminSettingsController(
                $session, $accounts, $admins, $login,
                new PdoGoogleOAuthSettings($pdo, $vault),
                new TwigRenderer(dirname(__DIR__, 2) . '/templates'),
                $pdo, CanonicalOrigin::fromServer($server) ?? '',
            ))->handle(
                $method,
                isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
                $body, (string) ($server['REMOTE_ADDR'] ?? ''), bin2hex(random_bytes(16)),
            );
        } elseif ($path === '/admin/settings/smtp') {
            $login = new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                new PdoLoginAttemptLimiter($pdo, $key),
                new PdoSecurityEventRecorder($pdo),
            );
            $response = (new SmtpAdminController(
                $session, $accounts, $admins, $login,
                new PdoSmtpSettings($pdo, $vault),
                new TwigRenderer(dirname(__DIR__, 2) . '/templates'),
                $pdo,
            ))->handle(
                $method,
                isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
                $body, (string) ($server['REMOTE_ADDR'] ?? ''), bin2hex(random_bytes(16)),
            );
        } else {
            $response = $adapter->dispatch(
                $method, $path, true,
                isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
                (string) ($server['REMOTE_ADDR'] ?? ''),
                bin2hex(random_bytes(16)), $body,
            );
        }

        // Admin HTML uses only the locally bundled Twig assets.
        if (str_starts_with($response['content_type'], 'text/html')) {
            $recaptcha = $path === '/admin/login'
                && $captchaSettings->summary()['provider'] === 'recaptcha';
            $response['csp'] = "default-src 'none'; style-src 'self'; script-src 'self'"
                . ($recaptcha ? " https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/" : "")
                . "; img-src 'self' data:"
                . ($recaptcha ? " https://www.google.com https://www.gstatic.com" : "")
                . "; font-src 'self'; form-action 'self'; "
                . ($recaptcha ? "connect-src https://www.google.com/recaptcha/; " : "")
                . ($recaptcha ? "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; " : "")
                . "base-uri 'none'; frame-ancestors 'none'; object-src 'none'";
        }
        return $response;
    }

    /** @return array{status:int,content_type:string,body:string,location?:string} */
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
