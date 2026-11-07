<?php

declare(strict_types=1);

namespace OPGateway\Tests\Integration;

use OPGateway\Database\DatabaseConfig;
use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Database\PdoConnectionFactory;
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

        $config = DatabaseConfig::fromEnvironment([
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '13306',
            'DB_NAME' => 'opgateway_integration_test',
            'DB_USER' => 'opgateway_test',
            'DB_PASSWORD' => $password,
        ]);
        $factory = new PdoConnectionFactory();
        $pdo = $factory->connect($config);
        self::assertSame('opgateway_integration_test', $pdo->query('SELECT DATABASE()')->fetchColumn());

        // Never overwrite or drop tables from a nonempty database.
        self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
            'Integration test requires a fresh, empty disposable database.');

        $runner = new MigrationRunner($pdo);
        $catalog = MigrationCatalog::all();
        $versions = array_map(static fn ($migration): string => $migration->version(), $catalog);

        self::assertSame($versions, $runner->run($catalog));
        self::assertSame([], $runner->run($catalog), 'Re-running completed migrations must be a no-op.');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['schema_migrations', 'users', 'roles', 'user_roles',
            'api_clients', 'api_credentials', 'client_ip_whitelists'] as $table) {
            self::assertContains($table, $tables);
        }
        self::assertSame('completed', $pdo->query(
            'SELECT status FROM schema_migrations LIMIT 1'
        )->fetchColumn());

        $other = $factory->connect($config);
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
