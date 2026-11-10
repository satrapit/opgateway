<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\UnifySettingsStorage;
use OPGateway\Database\Migrations\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class SettingsMigrationTest extends TestCase
{
    public function testMigrationPreservesEncryptedSmtpDataBeforeDroppingOldTable(): void
    {
        $migration = new UnifySettingsStorage();
        self::assertSame('202611100002_unify_settings_storage', $migration->version());
        $steps = $migration->statements();
        self::assertCount(3, $steps);
        self::assertStringContainsString('CREATE TABLE settings', $steps[0]);
        self::assertStringContainsString('PRIMARY KEY (setting_key)', $steps[0]);
        self::assertStringContainsString("SELECT 'smtp.password', password_cipher", $steps[1]);
        self::assertStringContainsString('FROM smtp_settings WHERE id = 1', $steps[1]);
        self::assertSame('DROP TABLE smtp_settings', $steps[2]);
        self::assertStringNotContainsString('AES_DECRYPT', implode("\n", $steps));
    }

    public function testManualUpgradeMatchesCanonicalMigrationAndChecksum(): void
    {
        $migration = new UnifySettingsStorage();
        $sql = file_get_contents(dirname(__DIR__) . '/database/sql/upgrade_202611100002.sql');
        self::assertIsString($sql);
        self::assertStringContainsString($migration->version(), $sql);
        self::assertStringContainsString(MigrationRunner::checksum($migration), $sql);
        self::assertSame(
            'cf5a225284549fe2f177fde4d5e6f6d2b61972f2035502eb55c9b2898ab5deae',
            MigrationRunner::checksum($migration),
        );
        foreach ($migration->statements() as $step) {
            self::assertStringContainsString($step . ';', $sql);
        }
    }
}
