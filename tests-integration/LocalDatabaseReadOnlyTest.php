<?php

declare(strict_types=1);

namespace OPGateway\Tests\Integration;

use OPGateway\Config\Environment;
use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\SchemaReadiness;
use OPGateway\Database\PdoConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Read-only acceptance check against the developer's existing local MySQL.
 *
 * Never creates, alters, seeds or deletes database data. The destructive
 * MigrationRunnerIntegrationTest is separate and must use an EMPTY sandbox DB.
 */
final class LocalDatabaseReadOnlyTest extends TestCase
{
    public function testLocalSchemaAndApplicationDependenciesAreReady(): void
    {
        if (getenv('OPGATEWAY_TEST_LOCAL_READONLY') !== '1') {
            self::markTestSkipped('Opt in explicitly with OPGATEWAY_TEST_LOCAL_READONLY=1.');
        }

        $envPath = getenv('OPGATEWAY_LOCAL_DB_ENV');
        if (!is_string($envPath) || $envPath === '') {
            $envPath = dirname(__DIR__) . '/.env';
        }
        $config = Environment::load($envPath);

        // This command must never accidentally contact a remote server.
        // Even if the host is local, all database operations below are reads.
        $host = strtolower($config['DB_HOST'] ?? '');
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Refusing non-loopback MySQL host for local acceptance test.');
        }

        $pdo = PdoConnectionFactory::open($config);
        // Defense in depth: MySQL also rejects writes for this transaction.
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $catalog = MigrationCatalog::all();
            self::assertCount(9, $catalog);
            $status = (new SchemaReadiness($pdo))->inspect($catalog);
            self::assertTrue($status['ready'],
                'Local schema is incomplete; review pending migrations after backup, do not auto-apply.');
            self::assertCount(9, $status['completed']);
            self::assertSame([], $status['missing']);

            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            self::assertCount(15, $tables);
            foreach ([
                'users', 'roles', 'user_roles', 'settings', 'schema_migrations',
                'email_verification_tokens', 'password_reset_tokens',
                'google_identities', 'google_identity_confirmations',
                'provider_connections', 'security_events',
            ] as $table) {
                self::assertContains($table, $tables);
            }
            self::assertNotContains('smtp_settings', $tables);

            $columns = $pdo->query(<<<'SQL'
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
SQL)->fetchAll(PDO::FETCH_COLUMN);
            foreach (['first_name', 'last_name', 'auth_session_version'] as $column) {
                self::assertContains($column, $columns);
            }

            // Never retrieve SMTP passwords or any other secrets.
            $stmt = $pdo->query(<<<'SQL'
SELECT setting_value FROM settings
WHERE setting_key = 'smtp.gmail_actions_enabled' LIMIT 1
SQL);
            $setting = $stmt->fetchColumn();
            self::assertTrue($setting === false || $setting === '0' || $setting === '1',
                'Gmail action setting must be absent or boolean.');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
