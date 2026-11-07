<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/**
 * Store hashed, single-use email ownership proofs.
 *
 * Never persist or log plaintext tokens.
 */
final class CreateEmailVerificationTokens implements Migration
{
    public function version(): string
    {
        return '202611070002_create_email_verification_tokens';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return [
            <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
