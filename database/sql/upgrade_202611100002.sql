-- OPGateway migration 005: existing database already upgraded through 004.
-- In phpMyAdmin, select the intended database (e.g. local opgateway).
-- Back up first. Run ONCE, never against a database missing migration 004.
-- Copy all SMTP values before dropping the old table; encrypted value
-- smtp.password is transferred byte-for-byte, not decrypted/re-encrypted.
-- MySQL DDL auto-commits; if interrupted DO NOT rerun without inspection.
SELECT version, status FROM schema_migrations ORDER BY version;

INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611100002_unify_settings_storage', 'cf5a225284549fe2f177fde4d5e6f6d2b61972f2035502eb55c9b2898ab5deae', 'running', UTC_TIMESTAMP(6));

CREATE TABLE settings (
    setting_key VARCHAR(128) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
SELECT 'smtp.from_name', from_name, updated_at FROM smtp_settings WHERE id = 1;

DROP TABLE smtp_settings;

UPDATE schema_migrations
SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611100002_unify_settings_storage'
  AND checksum = 'cf5a225284549fe2f177fde4d5e6f6d2b61972f2035502eb55c9b2898ab5deae'
  AND status = 'running';

SELECT version, status, checksum FROM schema_migrations ORDER BY version;
SELECT COUNT(*) AS table_count FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';
SELECT setting_key, CASE WHEN setting_key = 'smtp.password' THEN '[encrypted]'
ELSE setting_value END AS setting_value FROM settings ORDER BY setting_key;
