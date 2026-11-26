<?php

declare(strict_types=1);

namespace OPGateway\Tests\Integration;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Database\PdoConnectionFactory;
use OPGateway\Identity\EmailVerificationService;
use OPGateway\Identity\PdoEmailVerificationRepository;
use OPGateway\Mail\PdoSmtpSettings;
use OPGateway\Security\SealedSecret;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Real-MySQL integration test. Intentionally excluded from the default suite.
 *
 * Uses one disposable, empty database exposed on localhost port 13306 only.
 * Never point this test at a production database: the database is mutated.
 */
final class MigrationRunnerIntegrationTest extends TestCase
{
    public function testMigrationExecutionAndSafetyGates(): void
    {
        $password = getenv('OPGATEWAY_TEST_MYSQL_PASSWORD');
        if (!is_string($password) || $password === '') {
            throw new RuntimeException('Set OPGATEWAY_TEST_MYSQL_PASSWORD for the disposable container.');
        }

        $config = [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '13306',
            'DB_NAME' => 'opgateway_integration_test',
            'DB_USER' => 'opgateway_test',
            'DB_PASSWORD' => $password,
        ];
        $pdo = PdoConnectionFactory::open($config);
        self::assertSame('opgateway_integration_test', $pdo->query('SELECT DATABASE()')->fetchColumn());

        // Never overwrite or drop tables from a nonempty database.
        self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
            'Integration test requires a fresh, empty disposable database.');

        $runner = new MigrationRunner($pdo);
        $catalog = MigrationCatalog::all();
        $versions = array_map(static fn ($migration): string => $migration->version(), $catalog);
        $preOptional = array_slice($catalog, 0, -1);
        $preOptionalVersions = array_slice($versions, 0, -1);
        self::assertCount(9, $catalog, 'Current development schema contains nine migrations.');
        self::assertSame('202611120002_add_user_names', end($versions));

        self::assertSame('utf8mb4_unicode_ci', $pdo->query(
            'SELECT @@collation_connection'
        )->fetchColumn());
        self::assertSame($preOptionalVersions, $runner->run($preOptional));
        self::assertCount(8, $preOptionalVersions);
        self::assertFalse($pdo->query(<<<'SQL'
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
AND COLUMN_NAME = 'first_name'
SQL)->fetchColumn(), 'Profile columns must be added by migration 009, not earlier.');

        $existingHash = password_hash('integration-existing-password', PASSWORD_DEFAULT);
        self::assertIsString($existingHash);
        $seed = $pdo->prepare(<<<'SQL'
INSERT INTO users (
    email, password_hash, status, email_verified_at, approved_at,
    signup_source, created_at, updated_at
) VALUES (?, ?, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6),
          'manual', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $seed->execute(['existing@example.test', $existingHash]);
        $userId = (int) $pdo->lastInsertId();
        $identity = $pdo->prepare(<<<'SQL'
INSERT INTO google_identities (user_id, google_sub, google_email, linked_at)
VALUES (?, 'integration-stable-subject', 'existing@example.test', UTC_TIMESTAMP(6))
SQL);
        $identity->execute([$userId]);
        $usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        self::assertSame([end($versions)], $runner->run($catalog));
        self::assertSame([], $runner->run($catalog), 'Re-running completed migrations must be a no-op.');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['schema_migrations', 'users', 'roles', 'user_roles',
            'api_clients', 'api_credentials', 'client_ip_whitelists'] as $table) {
            self::assertContains($table, $tables);
        }
        self::assertCount(15, $tables);
        self::assertContains('google_identities', $tables);
        self::assertContains('google_identity_confirmations', $tables);
        self::assertContains('password_reset_tokens', $tables);
        self::assertContains('provider_connections', $tables);
        self::assertContains('settings', $tables);
        self::assertNotContains('smtp_settings', $tables);
        self::assertSame(9, (int) $pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE status = 'completed'"
        )->fetchColumn());
        self::assertSame('completed', $pdo->query(
            'SELECT status FROM schema_migrations LIMIT 1'
        )->fetchColumn());
        $passwordColumn = $pdo->query(<<<'SQL'
SELECT IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
AND COLUMN_NAME = 'password_hash'
SQL)->fetchColumn();
        self::assertSame('YES', $passwordColumn);
        foreach (['first_name', 'last_name'] as $nameColumn) {
            $column = $pdo->prepare(<<<'SQL'
SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
AND COLUMN_NAME = ?
SQL);
            $column->execute([$nameColumn]);
            self::assertSame([
                'COLUMN_TYPE' => 'varchar(100)',
                'IS_NULLABLE' => 'YES',
            ], $column->fetch(PDO::FETCH_ASSOC),
                'Migration 009 must create nullable profile name fields.');
        }
        self::assertSame($usersBefore, (int) $pdo->query(
            'SELECT COUNT(*) FROM users'
        )->fetchColumn(), 'Migration 009 must preserve all existing users.');
        $versionColumn = $pdo->query(<<<'SQL'
SELECT CONCAT(COLUMN_TYPE, ':', IS_NULLABLE, ':', COLUMN_DEFAULT)
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
AND COLUMN_NAME = 'auth_session_version'
SQL)->fetchColumn();
        self::assertSame('bigint unsigned:NO:1', $versionColumn);
        $preserved = $pdo->prepare(
            'SELECT password_hash, auth_session_version, first_name, last_name FROM users WHERE id = ?',
        );
        $preserved->execute([$userId]);
        self::assertSame([
            'password_hash' => $existingHash,
            'auth_session_version' => 1,
            'first_name' => null,
            'last_name' => null,
        ], $preserved->fetch(PDO::FETCH_ASSOC));
        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM google_identities'
        )->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM google_identity_confirmations'
        )->fetchColumn());

        // Real PDO/MySQL settings toggle: default off, persisted, and reversible.
        $smtpSettings = new PdoSmtpSettings(
            $pdo, new SealedSecret(bin2hex(random_bytes(32))),
        );
        self::assertFalse($smtpSettings->gmailActionsEnabled());
        $smtpSettings->saveGmailActions(true);
        self::assertTrue($smtpSettings->gmailActionsEnabled());
        $smtpSettings->saveGmailActions(false);
        self::assertFalse($smtpSettings->gmailActionsEnabled());

        // Real InnoDB token consumption: self-service is approved once,
        // while a manual invitation still requires administrator review.
        $insertPending = $pdo->prepare(<<<'SQL'
INSERT INTO users (
    email, password_hash, status, signup_source, created_at, updated_at
) VALUES (?, NULL, 'pending', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $verification = new EmailVerificationService(
            new PdoEmailVerificationRepository($pdo, true),
        );
        foreach ([
            ['self-service-integration@example.test', 'self_service', 'active'],
            ['invited-integration@example.test', 'manual', 'pending'],
        ] as [$email, $source, $expectedStatus]) {
            $insertPending->execute([$email, $source]);
            $pendingUserId = (int) $pdo->lastInsertId();
            $rawToken = $verification->issueForUser($pendingUserId);
            self::assertSame(64, strlen($rawToken));
            $rawTokenLookup = $pdo->prepare(
                'SELECT COUNT(*) FROM email_verification_tokens WHERE token_hash = ?'
            );
            $rawTokenLookup->execute([$rawToken]);
            self::assertSame(0, (int) $rawTokenLookup->fetchColumn(),
                'Raw bearer tokens must never be persisted.');
            self::assertTrue($verification->verify($rawToken));
            self::assertFalse($verification->verify($rawToken),
                'A consumed token must not be reusable.');
            $account = $pdo->prepare(
                'SELECT status, email_verified_at, approved_at FROM users WHERE id = ?'
            );
            $account->execute([$pendingUserId]);
            $row = $account->fetch(PDO::FETCH_ASSOC);
            self::assertSame($expectedStatus, $row['status']);
            self::assertNotNull($row['email_verified_at']);
            if ($source === 'self_service') {
                self::assertNotNull($row['approved_at']);
            } else {
                self::assertNull($row['approved_at']);
            }
        }

        $other = PdoConnectionFactory::open($config);
        self::assertSame(1, (int) $other->query(
            "SELECT GET_LOCK('opgateway_schema_migrations', 0)"
        )->fetchColumn());
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Migration lock unavailable.');
            $runner->run($catalog);
        } finally {
            $other->query("SELECT RELEASE_LOCK('opgateway_schema_migrations')");
        }
    }
}
