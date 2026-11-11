<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/**
 * Additive migration 008 for Google-first accounts and durable session revocation.
 *
 * Existing password hashes are preserved. The version starts at one for every
 * existing user and is incremented whenever an authentication method changes.
 */
final class EnableOptionalPasswords implements Migration
{
    public function version(): string
    {
        return '202611120001_enable_optional_passwords';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return [
            <<<'SQL'
ALTER TABLE users
MODIFY password_hash VARCHAR(255) NULL,
ADD auth_session_version BIGINT UNSIGNED NOT NULL DEFAULT 1
SQL,
            <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
