<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\AdministratorBootstrap;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Database\PdoConnectionFactory;
use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\SchemaReadiness;
use PDO;
use OPGateway\Security\PdoSetupAttemptLimiter;
use Throwable;

/**
 * Explicitly opt-in, operator-only first-admin installer.
 *
 * No secrets, request bodies or exception details may be logged.
 * Requires trusted HTTPS, exact REMOTE_ADDR, CSRF and 256-bit token.
 */
final readonly class SetupInstaller
{
    /** @param array<string,string> $config */
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string,mixed> $server Server-controlled PHP metadata.
     * @return array{status:int,content_type:string,body:string}
     */
    public function handle(string $method, array $server): array
    {
        if (($this->config['SETUP_ENABLED'] ?? '') !== 'true') {
            return self::error(404);
        }
        if (($server['HTTPS'] ?? '') !== 'on' || !self::allowedIp(
            (string) ($server['REMOTE_ADDR'] ?? ''),
            $this->config['SETUP_ALLOWED_IP'] ?? '',
        )) {
            return self::error(404);
        }
        $origin = $this->config['APP_URL'] ?? '';
        if (!preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~iD', $origin)
            || !preg_match('/^[0-9a-f]{64}$/D', $this->config['SETUP_TOKEN_HASH'] ?? '')
            || !preg_match('/^[0-9a-f]{64}$/D', $this->config['SETUP_RATE_LIMIT_KEY'] ?? '')) {
            return self::error(503);
        }

        // Check the authoritative database state before rendering the
        // privileged setup form. The bootstrap transaction repeats this
        // check under a MySQL lock to prevent concurrent installations.
        try {
            $pdo = PdoConnectionFactory::open($this->config);
            if (!(new SchemaReadiness($pdo))->inspect(MigrationCatalog::all())['ready']) {
                return self::error(503);
            }
            if (!self::emptyIdentity($pdo)) {
                return self::error(404);
            }
        } catch (Throwable) {
            return self::error(503);
        }

        $session = new BrowserSession();
        $session->start(true);
        if ($method === 'GET') {
            $csrf = htmlspecialchars($session->csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>OPGateway administrator setup</title></head><body>'
                . '<main><h1>First administrator setup</h1>'
                . '<form method="post" action="/setup/administrator">'
                . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                . '<label>Email <input type="email" name="email" maxlength="254" required></label>'
                . '<label>Password <input type="password" name="password" minlength="12" maxlength="1024" autocomplete="new-password" required></label>'
                . '<label>Setup token <input type="password" name="setup_token" minlength="64" maxlength="64" autocomplete="off" required></label>'
                . '<button type="submit">Create initial administrator</button>'
                . '</form></main></body></html>';
            return ['status' => 200, 'content_type' => 'text/html; charset=utf-8', 'body' => $html];
        }
        if ($method !== 'POST') {
            return self::error(405);
        }
        if (!hash_equals($origin, (string) ($server['HTTP_ORIGIN'] ?? ''))
            || !preg_match('~^application/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$~iD',
                (string) ($server['CONTENT_TYPE'] ?? ''))) {
            return self::error(403);
        }

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
        $form = $body === null ? null : StrictForm::decode(
            $body, ['_csrf', 'email', 'password', 'setup_token'],
        );
        if ($form === null || !$session->csrfValid($form['_csrf'])
            || strlen($form['email']) > 254 || strlen($form['password']) > 1024) {
            return self::error(400);
        }

        try {
            // Reserve an IP-scoped attempt BEFORE checking the setup token.
            // A malformed/incorrect token still consumes a persistent attempt.
            $limiter = new PdoSetupAttemptLimiter(
                $pdo,
                hex2bin($this->config['SETUP_RATE_LIMIT_KEY']),
            );
            if (!$limiter->allow((string) $server['REMOTE_ADDR'])) {
                return self::error(429);
            }
            $bootstrap = new AdministratorBootstrap($pdo, new PasswordHasher());
            $bootstrap->create(
                $form['email'], $form['password'], $form['setup_token'],
                $this->config['SETUP_TOKEN_HASH'],
            );
        } catch (Throwable) {
            // Do not disclose account existence or token validity.
            return self::error(403);
        }
        $session->logout();
        return self::json(201, ['status' => 'administrator_created',
            'next' => 'Disable setup and remove its token digest from private configuration.']);
    }

    /**
     * An existing identity, role, or role assignment permanently closes
     * the first-time installer. Never delete users to reopen setup.
     */
    private static function emptyIdentity(PDO $pdo): bool
    {
        foreach (['users', 'roles', 'user_roles'] as $table) {
            // Table names are fixed internal literals, never user input.
            $stmt = $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            if ($stmt->fetchColumn() !== false) {
                return false;
            }
        }
        return true;
    }

    private static function allowedIp(string $remote, string $expected): bool
    {
        $a = @inet_pton($remote);
        $b = @inet_pton($expected);
        return is_string($a) && is_string($b) && hash_equals($b, $a);
    }

    /** @param array<string,string> $payload
     *  @return array{status:int,content_type:string,body:string}
     */
    private static function json(int $status, array $payload): array
    {
        return ['status' => $status, 'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status): array
    {
        return self::json($status, ['error' => match ($status) {
            400 => 'invalid_request',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            429 => 'too_many_requests',
            default => 'service_unavailable',
        }]);
    }
}
