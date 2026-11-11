<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\EnableOptionalPasswords;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Http\AccountViews;
use OPGateway\Identity\AccountSecurityService;
use OPGateway\Identity\AuthenticationSessionVersion;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PdoGoogleIdentityStore;
use OPGateway\Security\CredentialActionLimiter;
use OPGateway\Security\PdoSecurityEventRecorder;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GoogleFirstOptionalPasswordTest extends TestCase
{
    public function testMigrationEightPreservesHashesAndAddsVersionAndConfirmation(): void
    {
        $migration = new EnableOptionalPasswords();
        $script = file_get_contents(
            dirname(__DIR__) . '/database/sql/upgrade_202611120001.sql',
        );
        self::assertIsString($script);
        self::assertStringContainsString(MigrationRunner::checksum($migration), $script);
        foreach ($migration->statements() as $statement) {
            self::assertStringContainsString($statement . ';', $script);
        }
        $sql = implode("\n", $migration->statements());
        self::assertStringContainsString('password_hash VARCHAR(255) NULL', $sql);
        self::assertStringContainsString('auth_session_version BIGINT UNSIGNED NOT NULL DEFAULT 1', $sql);
        self::assertStringContainsString('google_identity_confirmations', $sql);
        self::assertStringNotContainsString('DELETE FROM USERS', strtoupper($sql));
        self::assertStringNotContainsString('UPDATE users SET password_hash', $sql);
    }

    public function testNullPasswordAlwaysRunsDummyVerificationButNeverAuthenticates(): void
    {
        $repository = new class implements LoginAccountRepository {
            public function findEligibleByEmail(string $email): ?array
            {
                return ['id' => 9, 'password_hash' => null,
                    'session_stamp' => AuthenticationSessionVersion::stamp(1)];
            }
            public function isEligible(int $userId): bool
            {
                return true;
            }
            public function currentSessionStamp(int $userId): ?string
            {
                return AuthenticationSessionVersion::stamp(1);
            }
        };
        $authenticator = new LoginAuthenticator($repository, new PasswordHasher());
        self::assertNull($authenticator->authenticate(
            'google-only@example.test', 'any-password-value',
        ));
    }

    public function testConcurrentGoogleCallbackResolvesWinningSubjectTransaction(): void
    {
        $db = new class extends PDO {
            public bool $transaction = false;
            public bool $linkedByCompetitor = false;
            public int $begins = 0;
            public string $lastSql = '';
            public function __construct()
            {
            }
            public function beginTransaction(): bool
            {
                $this->transaction = true;
                $this->begins++;
                return true;
            }
            public function commit(): bool
            {
                $this->transaction = false;
                return true;
            }
            public function rollBack(): bool
            {
                $this->transaction = false;
                return true;
            }
            public function inTransaction(): bool
            {
                return $this->transaction;
            }
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this, $query) extends PDOStatement {
                    public function __construct(private PDO $db, private string $sql)
                    {
                    }
                    public function execute(?array $params = null): bool
                    {
                        $this->db->lastSql = $this->sql;
                        if (str_starts_with(trim($this->sql), 'INSERT INTO users')) {
                            $this->db->linkedByCompetitor = true;
                            throw new \PDOException('duplicate race', 23000);
                        }
                        return true;
                    }
                    public function fetch(
                        int $mode = PDO::FETCH_DEFAULT,
                        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                        int $cursorOffset = 0,
                    ): mixed {
                        if (str_contains($this->sql, 'WHERE g.google_sub = ?')
                            && $this->db->linkedByCompetitor) {
                            return [
                                'id' => 44, 'status' => 'active',
                                'email_verified_at' => '2026-01-01',
                                'approved_at' => '2026-01-01',
                                'auth_session_version' => 3,
                            ];
                        }
                        return false;
                    }
                    public function fetchColumn(int $column = 0): mixed
                    {
                        return false;
                    }
                };
            }
        };
        $result = (new PdoGoogleIdentityStore($db))->loginOrRegister(
            'stable-google-subject', 'owner@example.test',
        );
        self::assertSame('authenticated', $result['status']);
        self::assertSame(44, $result['user_id']);
        self::assertSame(AuthenticationSessionVersion::stamp(3), $result['session_stamp']);
        self::assertSame(2, $db->begins);
        self::assertFalse($db->transaction);
    }

    public function testSetAndChangePasswordIncrementVersionAndRejectWrongCurrentPassword(): void
    {
        $db = new class extends PDO {
            public ?string $passwordHash = null;
            public int $version = 1;
            public bool $transaction = false;
            public function __construct()
            {
            }
            public function beginTransaction(): bool
            {
                $this->transaction = true;
                return true;
            }
            public function commit(): bool
            {
                $this->transaction = false;
                return true;
            }
            public function rollBack(): bool
            {
                $this->transaction = false;
                return true;
            }
            public function inTransaction(): bool
            {
                return $this->transaction;
            }
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this, $query) extends PDOStatement {
                    public function __construct(private PDO $db, private string $sql)
                    {
                    }
                    public function execute(?array $params = null): bool
                    {
                        if (str_contains($this->sql, 'UPDATE users SET password_hash')) {
                            $this->db->passwordHash = $params[0];
                            $this->db->version++;
                        }
                        return true;
                    }
                    public function fetch(
                        int $mode = PDO::FETCH_DEFAULT,
                        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                        int $cursorOffset = 0,
                    ): mixed {
                        return ['password_hash' => $this->db->passwordHash];
                    }
                    public function rowCount(): int
                    {
                        return 1;
                    }
                };
            }
        };
        $limiter = $this->createStub(CredentialActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $events = new class implements SecurityEventRecorder {
            public array $codes = [];
            public function record(string $eventCode, string $requestId): void
            {
                $this->codes[] = $eventCode;
            }
        };
        $service = new AccountSecurityService(
            $db, new PasswordHasher(), $limiter, $events,
        );
        self::assertSame('ok', $service->setPassword(
            7, 'first-secure-password', '192.0.2.1', str_repeat('a', 32),
        ));
        self::assertTrue(password_verify('first-secure-password', (string) $db->passwordHash));
        self::assertSame(2, $db->version);
        self::assertSame('denied', $service->changePassword(
            7, 'wrong-password', 'second-secure-password',
            '192.0.2.1', str_repeat('b', 32),
        ));
        self::assertSame(2, $db->version);
        self::assertSame('ok', $service->changePassword(
            7, 'first-secure-password', 'second-secure-password',
            '192.0.2.1', str_repeat('c', 32),
        ));
        self::assertTrue(password_verify('second-secure-password', (string) $db->passwordHash));
        self::assertSame(3, $db->version);
        self::assertSame(['password_set', 'password_change_denied', 'password_changed'],
            $events->codes);
        self::assertFalse($db->transaction);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGoogleStepUpGrantIsAccountActionBoundShortLivedAndOneTime(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->grantGoogleAction(17, 'api_key_issue');
        self::assertFalse($session->consumeGoogleAction(17, 'provider_credentials'));
        // Looking for the wrong action must not consume a separately named grant.
        self::assertTrue($session->consumeGoogleAction(17, 'api_key_issue'));
        self::assertFalse($session->consumeGoogleAction(17, 'api_key_issue'));
        $session->grantGoogleAction(17, 'provider_credentials');
        self::assertFalse($session->consumeGoogleAction(18, 'provider_credentials'));
        $session->grantGoogleAction(17, 'api_key_issue');
        $_SESSION['opgateway_google_action_grants']['api_key_issue']['verified_at']
            = time() - 301;
        self::assertFalse($session->consumeGoogleAction(17, 'api_key_issue'));
        self::assertTrue(session_destroy());
    }

    public function testSecurityViewsExposeBothMethodsWithoutLeakingSecrets(): void
    {
        $csrf = str_repeat('a', 64);
        $googleOnly = AccountViews::security(
            $csrf, 'owner@example.test', false, true, 'owner@example.test',
        );
        self::assertStringContainsString('Account Security', $googleOnly);
        self::assertStringContainsString('Set password', $googleOnly);
        self::assertStringContainsString('at least one recovery method', $googleOnly);
        self::assertStringNotContainsString('action="/account/google/unlink"', $googleOnly);
        self::assertStringNotContainsString('google_sub', $googleOnly);

        $withPassword = AccountViews::security(
            $csrf, 'owner@example.test', true, true, 'owner@example.test',
        );
        self::assertStringContainsString('Change password', $withPassword);
        self::assertStringContainsString('action="/account/google/unlink"', $withPassword);
        self::assertStringContainsString('autocomplete="current-password"', $withPassword);
    }

    public function testAuditRecorderAcceptsOnlyMetadataCodes(): void
    {
        $db = new class extends PDO {
            public array $params = [];
            public function __construct()
            {
            }
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this) extends PDOStatement {
                    public function __construct(private PDO $db)
                    {
                    }
                    public function execute(?array $params = null): bool
                    {
                        $this->db->params = $params ?? [];
                        return true;
                    }
                };
            }
        };
        $recorder = new PdoSecurityEventRecorder($db);
        $recorder->record('google_signup_created', str_repeat('f', 32));
        self::assertSame(['google_signup_created', str_repeat('f', 32)], $db->params);
        $this->expectException(\InvalidArgumentException::class);
        $recorder->record('email=owner@example.test', str_repeat('f', 32));
    }
}
