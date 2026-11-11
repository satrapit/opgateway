-- OPGateway full schema: one-time import into a NEW, EMPTY MySQL database.
-- CAUTION: existing users, tokens, clients and records are NOT migrated.
-- Backup the current database before replacing it in cPanel.
-- Select the correct EMPTY database in phpMyAdmin before import.
-- STOP if any tables exist. No existing user data is dropped or truncated.
-- Migration 005 only drops the temporary legacy smtp_settings table from 004.
-- DDL can implicitly commit, so never rerun a failed import blindly.
-- Exactly 14 final tables and 7 completed migration records are expected.
-- Original migration DDL and checksums are preserved verbatim.
-- No administrator, users, keys or whitelist entries are created.
-- Migration 005 drops only the temporary legacy smtp_settings table created by 004.

-- Migration 001: identity, clients and migration ledger
CREATE TABLE schema_migrations (
    version VARCHAR(128) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611070001_create_identity_tables', 'fee0c5fbfd548e1cddb985befd9bd8a9a10ffb5b5255ac767b0432ca9e12c1a0', 'running', UTC_TIMESTAMP(6));

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME(6) NULL,
    approved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_role_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(128) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'disabled',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_api_clients_owner (owner_user_id),
    CONSTRAINT fk_api_clients_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_client_id BIGINT UNSIGNED NOT NULL,
    key_prefix VARCHAR(32) NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_credentials_prefix (key_prefix),
    KEY idx_api_credentials_client (api_client_id),
    CONSTRAINT fk_api_credentials_client FOREIGN KEY (api_client_id) REFERENCES api_clients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_ip_whitelists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_client_id BIGINT UNSIGNED NOT NULL,
    ip_address VARBINARY(16) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    approved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_ip (api_client_id, ip_address),
    CONSTRAINT fk_ip_whitelists_client FOREIGN KEY (api_client_id) REFERENCES api_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations
SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611070001_create_identity_tables'
  AND checksum = 'fee0c5fbfd548e1cddb985befd9bd8a9a10ffb5b5255ac767b0432ca9e12c1a0'
  AND status = 'running';

-- Migration 002: verified-email token storage
INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611070002_create_email_verification_tokens',
        '0bc2e4471188fb379f737af925ebce4dcfc00537ef7b7992b17d2153b57e7727',
        'running', UTC_TIMESTAMP(6));

CREATE TABLE email_verification_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verification_token_hash (token_hash),
    KEY idx_email_verification_user (user_id),
    KEY idx_email_verification_expires (expires_at),
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations
SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611070002_create_email_verification_tokens'
  AND checksum = '0bc2e4471188fb379f737af925ebce4dcfc00537ef7b7992b17d2153b57e7727'
  AND status = 'running';

-- Migration 003: login-rate-limits and safe event metadata
INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES (
  '202611070003_create_login_security_tables',
  'a2932ddac794ac00993a4077dd67d7ca4dd94ec9df971d81cf15b4518342d070',
  'running',
  UTC_TIMESTAMP(6)
);

CREATE TABLE login_rate_limits (
    bucket_hash BINARY(32) NOT NULL,
    window_number BIGINT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_hash, window_number),
    KEY idx_login_rate_limits_window (window_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_code VARCHAR(40) NOT NULL,
    request_id CHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_security_events_created (created_at),
    KEY idx_security_events_code_created (event_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations
SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611070003_create_login_security_tables'
  AND checksum = 'a2932ddac794ac00993a4077dd67d7ca4dd94ec9df971d81cf15b4518342d070'
  AND status = 'running';

-- Migration 004: self-service provenance, encrypted SMTP and upstream account settings
INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611100001_create_gateway_integrations', 'b5d6333be067cc33d03c2773ca24134dbb7ff8248b1f514ae8d0eb52b44893fd', 'running', UTC_TIMESTAMP(6));

ALTER TABLE users
ADD signup_source VARCHAR(16) NOT NULL DEFAULT 'manual';

CREATE TABLE smtp_settings (
    id TINYINT UNSIGNED NOT NULL,
    host VARCHAR(253) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    encryption VARCHAR(8) NOT NULL,
    username VARCHAR(254) NOT NULL,
    password_cipher TEXT NOT NULL,
    from_email VARCHAR(254) NOT NULL,
    from_name VARCHAR(128) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_connections (
    owner_user_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(254) NOT NULL,
    password_cipher TEXT NOT NULL,
    token_cipher TEXT NULL,
    token_expires_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (owner_user_id),
    CONSTRAINT fk_provider_connections_owner
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations
SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611100001_create_gateway_integrations' AND checksum = 'b5d6333be067cc33d03c2773ca24134dbb7ff8248b1f514ae8d0eb52b44893fd' AND status = 'running';

-- Migration 005: unify application settings in generic key/value storage
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
WHERE version = '202611100002_unify_settings_storage' AND checksum = 'cf5a225284549fe2f177fde4d5e6f6d2b61972f2035502eb55c9b2898ab5deae' AND status = 'running';

-- Migration 006: password reset token digests
INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611100003_create_password_reset_tokens', '1559639668e3a95948159a0da3c6e114be68a20e936b641ae25fd7ab6e436c69', 'running', UTC_TIMESTAMP(6));

CREATE TABLE password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_user (user_id),
    KEY idx_password_reset_expiry (expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611100003_create_password_reset_tokens' AND checksum = '1559639668e3a95948159a0da3c6e114be68a20e936b641ae25fd7ab6e436c69' AND status = 'running';

-- Migration 007: Google OIDC identity mapping
INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611110001_create_google_identities', '668039504a182a0dbb6c178897d901fbd5a1489b7bee31b424e1c70b1d2058c5', 'running', UTC_TIMESTAMP(6));

CREATE TABLE google_identities (
    user_id BIGINT UNSIGNED NOT NULL,
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    google_email VARCHAR(254) NOT NULL,
    linked_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_google_identities_sub (google_sub),
    CONSTRAINT fk_google_identities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611110001_create_google_identities'
AND checksum = '668039504a182a0dbb6c178897d901fbd5a1489b7bee31b424e1c70b1d2058c5' AND status = 'running';

-- Read-only checks after successful import:
SELECT DATABASE() AS selected_database;
SELECT version, status, checksum FROM schema_migrations ORDER BY version;
SELECT COUNT(*) AS table_count FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';
SELECT (SELECT COUNT(*) FROM users) AS users_count,
       (SELECT COUNT(*) FROM roles) AS roles_count,
       (SELECT COUNT(*) FROM user_roles) AS user_roles_count;
