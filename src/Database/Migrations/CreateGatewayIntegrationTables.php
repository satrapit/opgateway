<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/**
 * Immutable v0.2 schema for encrypted SMTP and per-owner upstream credentials.
 * No default SMTP account or Openprovider identity is created.
 */
final class CreateGatewayIntegrationTables implements Migration
{
    public function version(): string
    {
        return '202611100001_create_gateway_integrations';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return [
            <<<'SQL'
ALTER TABLE users
ADD signup_source VARCHAR(16) NOT NULL DEFAULT 'manual'
SQL,
            <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
