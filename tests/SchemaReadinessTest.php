<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class SchemaReadinessTest extends TestCase
{
    public function testCanonicalMigrationCatalogHasFiveOrderedVersions(): void
    {
        $migrations = MigrationCatalog::all();
        self::assertSame([
            '202611070001_create_identity_tables',
            '202611070002_create_email_verification_tokens',
            '202611070003_create_login_security_tables',
            '202611100001_create_gateway_integrations',
            '202611100002_unify_settings_storage',
        ], array_map(static fn ($migration) => $migration->version(), $migrations));

        foreach ($migrations as $migration) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', MigrationRunner::checksum($migration));
        }
    }
}
