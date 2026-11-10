-- OPGateway upgrade 004 for EXISTING populated databases only.
-- Take a verified backup, select the intended DB in phpMyAdmin, verify
-- migration history 001-003 is completed, and execute ONCE only.
-- DDL implicitly commits. If interrupted, stop and inspect manually.
-- Never import opgateway_full_schema.sql into this database.
SELECT DATABASE() AS selected_database;
SELECT version, status FROM schema_migrations ORDER BY version;

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

SELECT version, checksum, status FROM schema_migrations
WHERE version = '202611100001_create_gateway_integrations';
