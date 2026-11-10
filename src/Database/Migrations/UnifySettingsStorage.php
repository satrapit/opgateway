<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/**
 * Migration 005: consolidate application settings into one key/value table.
 *
 * Copies legacy SMTP settings (including existing encrypted ciphertext)
 * before dropping the redundant table. Migration 004 remains immutable.
 * DDL is not transactional; apply once, from a verified database backup.
 */
final class UnifySettingsStorage implements Migration
{
    public function version(): string
    {
        return '202611100002_unify_settings_storage';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE settings (
    setting_key VARCHAR(128) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
INSERT INTO settings (setting_key, setting_value, updated_at)
SELECT 'smtp.host', host, updated_at FROM smtp_settings WHERE id = 1
UNION ALL
SELECT 'smtp.port', CAST(port AS CHAR), updated_at FROM smtp_settings WHERE id = 1
UNION ALL
SELECT 'smtp.encryption', encryption, updated_at FROM smtp_settings WHERE id = 1
UNION ALL
SELECT 'smtp.username', username, updated_at FROM smtp_settings WHERE id = 1
UNION ALL
SELECT 'smtp.password', password_cipher, updated_at FROM smtp_settings WHERE id = 1
UNION ALL
SELECT 'smtp.from_email', from_email, updated_at FROM smtp_settings WHERE id = 1
UNION ALL
SELECT 'smtp.from_name', from_name, updated_at FROM smtp_settings WHERE id = 1
SQL,
            <<<'SQL'
DROP TABLE smtp_settings
SQL,
        ];
    }
}
