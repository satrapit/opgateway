<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/**
 * Initial identity and API client schema for MySQL/MariaDB.
 *
 * No default administrator, password, credential or whitelist entry is created.
 * A fresh install is therefore deny-by-default until separately provisioned.
 */
final class CreateIdentityTables implements Migration
{
    public function version(): string
    {
        return '202611070001_create_identity_tables';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return [
            <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_role_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
