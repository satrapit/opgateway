<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\CreatePasswordResetTokens;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Http\AccountViews;
use OPGateway\Http\SelfServiceBrowserController;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PasswordResetMailer;
use OPGateway\Identity\PasswordResetService;
use OPGateway\Security\PdoPasswordResetLimiter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PasswordResetPolicyTest extends TestCase
{
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testRecoveryPagesRequirePostCsrfAndPasswordConfirmation(): void
    {
        $forgot = AccountViews::forgotPassword(self::CSRF);
        self::assertStringContainsString('action="/account/forgot-password"', $forgot);
        self::assertStringContainsString('name="_csrf"', $forgot);
        $reset = AccountViews::resetPasswordForm(self::CSRF, str_repeat('a', 64));
        self::assertStringContainsString('action="/account/reset-password"', $reset);
        self::assertStringContainsString('name="password_confirmation"', $reset);
        self::assertTrue(SelfServiceBrowserController::route('/account/forgot-password'));
        self::assertTrue(SelfServiceBrowserController::route('/account/reset-password'));
    }

    public function testMalformedTokenDoesNotShowPasswordForm(): void
    {
        $page = AccountViews::resetPasswordForm(self::CSRF, '<script>');
        self::assertStringNotContainsString('action="/account/reset-password"', $page);
        self::assertStringContainsString('invalid or expired', $page);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResetLinkGetChecksDatabaseWithoutConsumingAndPostRemainsProtected(): void
    {
        // A PDO double models an unused token (found), then a consumed or
        // expired token (not found), without requiring a live MySQL server.
        $database = new class (true) extends PDO {
            public string $sql = '';
            public array $params = [];

            public function __construct(public bool $usable)
            {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->sql = $query;
                return new class ($this) extends PDOStatement {
                    public function __construct(private PDO $database)
                    {
                    }

                    public function execute(?array $params = null): bool
                    {
                        $this->database->params = $params ?? [];
                        return true;
                    }

                    public function fetchColumn(int $column = 0): mixed
                    {
                        return $this->database->usable ? 1 : false;
                    }
                };
            }
        };
        $mailer = new class implements PasswordResetMailer {
            public function sendPasswordReset(string $email, string $token, string $origin): void
            {
                throw new \LogicException('GET must not send mail.');
            }
        };
        $recovery = new PasswordResetService(
            $database,
            new PasswordHasher(),
            new PdoPasswordResetLimiter($database, str_repeat('k', 32)),
            $mailer,
        );
        $session = new BrowserSession();
        $session->start(true);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $controller = new SelfServiceBrowserController(
            $session, $accounts, null, null, 'https://opgateway.test',
            null, null, $recovery,
        );
        $token = str_repeat('A', 64);
        $get = static fn (): array => $controller->dispatch(
            'GET', '/account/reset-password', null, null,
            '192.0.2.10', str_repeat('f', 32), 'token=' . $token,
        );

        $first = $get();
        self::assertSame(200, $first['status']);
        self::assertStringContainsString('action="/account/reset-password"', $first['body']);
        self::assertSame([hash('sha256', strtolower($token))], $database->params);
        self::assertStringContainsString('t.consumed_at IS NULL', $database->sql);
        self::assertStringContainsString('t.expires_at > UTC_TIMESTAMP(6)', $database->sql);
        self::assertStringContainsString("u.status = 'active'", $database->sql);
        self::assertStringNotContainsString('UPDATE ', $database->sql);

        $database->usable = false; // consumed or expired since the first GET
        $second = $get();
        self::assertSame(200, $second['status']);
        self::assertStringContainsString('Reset link unavailable', $second['body']);
        self::assertStringNotContainsString('action="/account/reset-password"', $second['body']);
        self::assertFalse($recovery->isUsable('not-a-token'));
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRateLimitedRecoveryReturnsReadableHtmlWithHttp429(): void
    {
        // Only the rate counter queries are reached when the request is limited.
        // The double represents an exhausted hourly rate-limit bucket.
        $database = new class extends PDO {
            public array $queries = [];

            public function __construct()
            {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->queries[] = $query;
                return new class extends PDOStatement {
                    public function __construct()
                    {
                    }

                    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
                    {
                        return true;
                    }

                    public function execute(?array $params = null): bool
                    {
                        return true;
                    }

                    public function fetchColumn(int $column = 0): mixed
                    {
                        return 11;
                    }
                };
            }
        };
        $mailer = new class implements PasswordResetMailer {
            public function sendPasswordReset(string $email, string $token, string $origin): void
            {
                throw new \LogicException('Rate-limited requests must not send email.');
            }
        };
        $recovery = new PasswordResetService(
            $database, new PasswordHasher(),
            new PdoPasswordResetLimiter($database, str_repeat('k', 32)), $mailer,
        );
        $session = new BrowserSession();
        $session->start(true);
        $controller = new SelfServiceBrowserController(
            $session, $this->createStub(LoginAccountRepository::class),
            null, null, 'https://opgateway.test', null, null, $recovery,
        );
        $response = $controller->dispatch(
            'POST', '/account/forgot-password',
            'application/x-www-form-urlencoded',
            http_build_query([
                '_csrf' => $session->csrfToken(),
                'email' => 'user@example.test',
            ]),
            '192.0.2.10', str_repeat('f', 32), '',
        );
        self::assertSame(429, $response['status']);
        self::assertSame('text/html; charset=utf-8', $response['content_type']);
        self::assertStringContainsString('Too many requests', $response['body']);
        self::assertStringContainsString('Please try again later', $response['body']);
        self::assertStringContainsString('/account/login', $response['body']);
        self::assertStringNotContainsString('"error":"too_many_requests"', $response['body']);
        self::assertCount(4, $database->queries);
        self::assertTrue(session_destroy());
    }

    public function testManualUpgradeMatchesHashedTokenMigration(): void
    {
        $migration = new CreatePasswordResetTokens();
        $script = file_get_contents(dirname(__DIR__) . '/database/sql/upgrade_202611100003.sql');
        self::assertIsString($script);
        self::assertStringContainsString(MigrationRunner::checksum($migration), $script);
        foreach ($migration->statements() as $statement) {
            self::assertStringContainsString($statement . ';', $script);
        }
        self::assertStringContainsString('token_hash CHAR(64)', $script);
        self::assertStringContainsString('consumed_at DATETIME(6)', $script);
    }
}
