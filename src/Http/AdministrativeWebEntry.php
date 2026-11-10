<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Database\Migrations\MigrationCatalog;
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
 * Opt-in admin browser HTTP entry; fail-closed until explicitly enabled.
 *
 * Sign-in and read-only review are gated by ADMIN_UI_ENABLED. Three
 * administrator mutations are gated independently by ADMIN_ACTIONS_ENABLED.
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
            DiagnosticLog::record('admin.configuration_invalid', 503);
            return self::error(503);
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
        );
        $review = ($this->config['ADMIN_ACTIONS_ENABLED'] ?? '') === 'true'
            ? new AdminReviewService(
                $pdo,
                new AdminActions(
                    new AccountApprovalService($pdo),
                    new ClientManagementService($pdo),
                ),
                new PdoAdminActionLimiter($pdo, hex2bin($key)),
                new PdoSecurityEventRecorder($pdo),
                new AdminClientProvisioningService($pdo),
            )
            : null;

        // Opt-in view migration. The legacy renderer remains intact if
        // Twig or compiled assets have not yet been deployed.
        $twigEnabled = ($this->config['ADMIN_TWIG_UI_ENABLED'] ?? '') === 'true';
        if ($twigEnabled) {
            // Explicit opt-in requires private Composer runtime and the exact
            // prebuilt local assets. No runtime build or unsafe fallback.
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

        $pages = $twigEnabled
            ? new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__, 2) . '/templates'))
            : null;

        $adapter = new PrivateBrowserAdapter(
            $session, $auth, $accounts, $admins,
            new AdminOverviewRepository($pdo), $review, $pages,
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

        $response = $adapter->dispatch(
            $method,
            $path,
            true,
            isset($server['CONTENT_TYPE']) ? (string) $server['CONTENT_TYPE'] : null,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            bin2hex(random_bytes(16)),
            $body,
        );

        // Only Twig HTML pages need local scripts and styles. Existing
        // owner/legacy browser CSP is untouched.
        if ($twigEnabled && str_starts_with($response['content_type'], 'text/html')) {
            $response['csp'] = "default-src 'none'; style-src 'self'; script-src 'self'; "
                . "img-src 'self' data:; font-src 'self'; form-action 'self'; "
                . "base-uri 'none'; frame-ancestors 'none'; object-src 'none'";
        }
        return $response;
    }

    private static function exactIp(string $actual, string $allowed): bool
    {
        $a = @inet_pton($actual);
        $b = @inet_pton($allowed);
        return is_string($a) && is_string($b) && hash_equals($b, $a);
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
