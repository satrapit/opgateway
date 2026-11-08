<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\MigrationCatalog;
use PHPUnit\Framework\TestCase;

final class MigrationCatalogTest extends TestCase
{
    public function testMigrationVersionsAreUniqueAndSorted(): void
    {
        $versions = array_map(static fn ($migration) => $migration->version(), MigrationCatalog::all());
        $sorted = $versions;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $versions);
        self::assertSame(count($versions), count(array_unique($versions)));
    }

    public function testInitialSchemaContainsNoSecretSeedsOrDefaultAdmin(): void
    {
        $migrations = MigrationCatalog::all();
        self::assertNotEmpty($migrations);
        $sql = implode("\n", $migrations[0]->statements());

        foreach (['users', 'roles', 'user_roles', 'api_clients', 'api_credentials', 'client_ip_whitelists'] as $table) {
            self::assertStringContainsString('CREATE TABLE ' . $table, $sql);
        }

        foreach ($migrations as $migration) {
            $statements = implode("\n", $migration->statements());
            self::assertStringNotContainsString('INSERT INTO', strtoupper($statements));
            self::assertStringNotContainsString('DROP TABLE', strtoupper($statements));
        }
    }

    public function testNewClientsAndWhitelistsStartDisabled(): void
    {
        $sql = implode("\n", MigrationCatalog::all()[0]->statements());
        self::assertStringContainsString("status VARCHAR(20) NOT NULL DEFAULT 'disabled'", $sql);
        self::assertStringContainsString('enabled TINYINT(1) NOT NULL DEFAULT 0', $sql);
    }
}
