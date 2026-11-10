<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\CreateGatewayIntegrationTables;
use OPGateway\Database\Migrations\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class SignupMigrationPolicyTest extends TestCase
{
    public function testIncrementalMigrationPreservesManualAccountApproval(): void
    {
        $migration = new CreateGatewayIntegrationTables();
        self::assertSame('202611100001_create_gateway_integrations', $migration->version());
        $sql = implode("\n", $migration->statements());
        self::assertStringContainsString("ADD signup_source VARCHAR(16) NOT NULL DEFAULT 'manual'", $sql);
        self::assertStringContainsString('CREATE TABLE smtp_settings', $sql);
        self::assertStringContainsString('CREATE TABLE provider_connections', $sql);
        self::assertStringNotContainsString('DROP TABLE', $sql);
        self::assertStringNotContainsString('TRUNCATE TABLE', $sql);
        self::assertStringNotContainsString('password_plaintext', $sql);
    }

    public function testUpgradeSqlCarriesExactVersionAndChecksum(): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/database/sql/upgrade_202611100001.sql');
        self::assertIsString($sql);
        $migration = new CreateGatewayIntegrationTables();
        self::assertStringContainsString($migration->version(), $sql);
        self::assertStringContainsString(MigrationRunner::checksum($migration), $sql);
        foreach ($migration->statements() as $statement) {
            self::assertStringContainsString($statement . ';', $sql);
        }
    }
}
