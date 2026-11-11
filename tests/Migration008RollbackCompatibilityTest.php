<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Identity\AuthenticationSessionVersion;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PdoGoogleIdentityStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Regression contract for a code-only rollback on a migration-008 database. */
final class Migration008RollbackCompatibilityTest extends TestCase
{
    public function testRollbackCatalogStillRequiresAllEightMigrations(): void
    {
        $versions = array_map(
            static fn ($migration): string => $migration->version(),
            MigrationCatalog::all(),
        );

        self::assertCount(8, $versions);
        self::assertSame('202611120001_enable_optional_passwords', $versions[7]);
    }

    public function testLinkedGoogleOnlyUserAuthenticatesBySubjectAndSessionVersion(): void
    {
        $database = new class extends PDO {
            public string $sql = '';
            public array $params = [];

            public function __construct()
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

                    public function fetch(
                        int $mode = PDO::FETCH_DEFAULT,
                        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                        int $cursorOffset = 0,
                    ): mixed {
                        return [
                            'id' => 23,
                            'status' => 'active',
                            'email_verified_at' => '2026-11-12 00:00:00.000000',
                            'approved_at' => '2026-11-12 00:00:00.000000',
                            'auth_session_version' => 4,
                        ];
                    }
                };
            }
        };

        $result = (new PdoGoogleIdentityStore($database))->loginBySubject(
            'stable-google-subject',
        );

        self::assertSame('authenticated', $result['status']);
        self::assertSame(23, $result['user_id']);
        self::assertSame(AuthenticationSessionVersion::stamp(4), $result['session_stamp']);
        self::assertSame(['stable-google-subject'], $database->params);
        self::assertStringContainsString('WHERE g.google_sub = ?', $database->sql);
        self::assertStringContainsString('auth_session_version', $database->sql);
        self::assertStringNotContainsString('password_hash', $database->sql);
        self::assertStringNotContainsString('INSERT', $database->sql);
    }

    public function testNullablePasswordRemainsFailClosedForPasswordLogin(): void
    {
        $accounts = new class implements LoginAccountRepository {
            public function findEligibleByEmail(string $email): ?array
            {
                return [
                    'id' => 23,
                    'password_hash' => null,
                    'session_stamp' => AuthenticationSessionVersion::stamp(1),
                ];
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

        self::assertNull((new LoginAuthenticator(
            $accounts,
            new PasswordHasher(),
        ))->authenticate('google-only@example.test', 'not-a-local-password'));
    }

    public function testPasswordRecoveryKeepsGoogleOnlyUsersAndRevokesSessions(): void
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/src/Identity/PasswordResetService.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString('UPDATE users SET password_hash = ?', $source);
        self::assertStringContainsString(
            'auth_session_version = auth_session_version + 1',
            $source,
        );
        self::assertStringNotContainsString('password_hash IS NOT NULL', $source);
    }

    public function testPreparedRollbackDeniesNewGoogleAccountsWithoutHidingLinkedLogin(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__) . '/src/Http/GoogleOidcBrowserController.php',
        );
        $entry = file_get_contents(dirname(__DIR__) . '/src/Http/AccountWebEntry.php');
        self::assertIsString($controller);
        self::assertIsString($entry);

        self::assertStringContainsString(
            '$this->identities->loginBySubject($google[\'sub\'])',
            $controller,
        );
        self::assertStringNotContainsString(
            '$this->identities->loginOrRegister(',
            $controller,
        );
        self::assertStringNotContainsString(
            '$registration !== null || $googleAvailable',
            $entry,
        );
        self::assertStringContainsString(
            '$googleAvailable, $captcha, $googleIdentities',
            $entry,
        );
    }
}
