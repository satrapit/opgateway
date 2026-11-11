<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class FullSchemaSqlTest extends TestCase
{
    public function testCompleteInstallContainsUnmodifiedPhpMigrations(): void
    {
        $path = dirname(__DIR__) . '/database/sql/opgateway_full_schema.sql';
        self::assertFileExists($path);
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        foreach (MigrationCatalog::all() as $migration) {
            self::assertStringContainsString($migration->version(), $sql);
            self::assertStringContainsString(MigrationRunner::checksum($migration), $sql);
            foreach ($migration->statements() as $statement) {
                self::assertStringContainsString($statement . ';', $sql);
            }
        }
        // Migration 004 creates a temporary SMTP table; migration 005
        // transfers its settings and removes that temporary table.
        self::assertSame(16, substr_count($sql, 'CREATE TABLE '));
        self::assertSame(1, substr_count($sql, 'DROP TABLE smtp_settings;'));
        self::assertDoesNotMatchRegularExpression('/^\s*TRUNCATE\s+/mi', $sql);
    }
}
