-- Migration 008: existing database must have completed 001-007.
-- Take and verify a local backup copy before reviewing this manual upgrade.
-- This script is additive/data-preserving and must never run automatically.
SELECT DATABASE() AS selected_database;
SELECT version, status FROM schema_migrations ORDER BY version;
INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611120001_enable_optional_passwords', '4f84d88b1db880865fff7879c71cd61c04dcfb9d81155eaa718b6a8fb95db760', 'running', UTC_TIMESTAMP(6));

ALTER TABLE users
MODIFY password_hash VARCHAR(255) NULL,
ADD auth_session_version BIGINT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE google_identity_confirmations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    google_email VARCHAR(254) NOT NULL,
    transaction_hash CHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_google_confirmation_token (token_hash),
    KEY idx_google_confirmation_user (user_id),
    KEY idx_google_confirmation_expiry (expires_at),
    CONSTRAINT fk_google_confirmation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611120001_enable_optional_passwords'
AND checksum = '4f84d88b1db880865fff7879c71cd61c04dcfb9d81155eaa718b6a8fb95db760' AND status = 'running';
