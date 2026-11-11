<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Database\Migrations\SchemaReadiness;
use PDO;
use PDOStatement;
use RuntimeException;
use PHPUnit\Framework\TestCase;

final class SchemaReadinessTest extends TestCase
{
    public function testCanonicalMigrationCatalogHasSevenOrderedVersions(): void
    {
        $migrations = MigrationCatalog::all();
        self::assertSame([
            '202611070001_create_identity_tables',
            '202611070002_create_email_verification_tokens',
            '202611070003_create_login_security_tables',
            '202611100001_create_gateway_integrations',
            '202611100002_unify_settings_storage',
            '202611100003_create_password_reset_tokens',
            '202611110001_create_google_identities',
        ], array_map(static fn ($migration) => $migration->version(), $migrations));

        foreach ($migrations as $migration) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', MigrationRunner::checksum($migration));
        }
    } 
    /** @return list<array{version:string,checksum:string,status:string}> */
    private static function allMigrationRows(): array
    {
        return array_map(static fn ($migration): array => [
            'version' => $migration->version(),
            'checksum' => MigrationRunner::checksum($migration),
            'status' => 'completed',
        ], MigrationCatalog::all());
    }

    /** @param list<array{version:string,checksum:string,status:string}> $rows */
    private static function connection(array $rows): PDO
    {
        return new class($rows) extends PDO {
            public function __construct(private array $rows)
            {
            }

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : null;
            }

            public function query(
                string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs,
            ): PDOStatement|false {
                return new class($this->rows) extends PDOStatement {
                    public function __construct(private array $rows)
                    {
                    }

                    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
                    {
                        return $this->rows;
                    }
                };
            }
        };
    }

    public function testSevenMigrationBaselineReady(): void
    {
        $result = (new SchemaReadiness(self::connection(self::allMigrationRows())))
            ->inspect(MigrationCatalog::all());
        self::assertTrue($result['ready']);
        self::assertCount(7, $result['completed']);
        self::assertSame([], $result['missing']);
    }

    public function testSixMigrationBaselineNotReadyForGoogleCode(): void
    {
        $rows = self::allMigrationRows();
        array_pop($rows);
        $result = (new SchemaReadiness(self::connection($rows)))
            ->inspect(MigrationCatalog::all());
        self::assertFalse($result['ready']);
        self::assertCount(6, $result['completed']);
        self::assertSame(['202611110001_create_google_identities'], $result['missing']);
    }

    public function testGoogleMigrationWithAlteredChecksumIsRejected(): void
    {
        $rows = self::allMigrationRows();
        $rows[6]['checksum'] = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        (new SchemaReadiness(self::connection($rows)))->inspect(MigrationCatalog::all());
    }

    public function testIncompleteGoogleMigrationIsRejected(): void
    {
        $rows = self::allMigrationRows();
        $rows[6]['status'] = 'running';
        $this->expectException(RuntimeException::class);
        (new SchemaReadiness(self::connection($rows)))->inspect(MigrationCatalog::all());
    }

    public function testUnknownFutureMigrationIsStillRejected(): void
    {
        $rows = self::allMigrationRows();
        $rows[] = [
            'version' => '202611120001_unknown_migration',
            'checksum' => str_repeat('1', 64),
            'status' => 'completed',
        ];
        $this->expectException(RuntimeException::class);
        (new SchemaReadiness(self::connection($rows)))->inspect(MigrationCatalog::all());
    }

    public function testSkippedPrerequisiteStillFailsClosed(): void
    {
        $rows = self::allMigrationRows();
        array_splice($rows, 3, 1);
        $this->expectException(RuntimeException::class);
        (new SchemaReadiness(self::connection($rows)))
            ->inspect(MigrationCatalog::all());
    }
}
