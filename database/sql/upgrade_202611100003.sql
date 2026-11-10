-- Password reset migration 006: existing DB must have migrations 001-005.
-- Back up the target database and apply this script manually, once only.
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
