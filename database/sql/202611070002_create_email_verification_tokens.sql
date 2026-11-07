-- OPGateway email verification: cPanel/phpMyAdmin import.
-- Select arsamnet_opgateway_db explicitly before importing.
-- Prerequisite: initial migration status is completed; table does not exist.
-- Backup database. Import ONCE, stop on errors. Never DROP or re-import.
-- Checksum matches CreateEmailVerificationTokens::statements().

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

-- After import, confirm both version rows show 'completed'.
-- SELECT version, status, checksum FROM schema_migrations ORDER BY version;
-- SHOW TABLES;
