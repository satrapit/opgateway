-- OPGateway initial schema import for cPanel/phpMyAdmin.
-- Migration: 202611070001_create_identity_tables
-- Database: arsamnet_opgateway_db (SELECT IT in phpMyAdmin BEFORE importing).
-- Import ONCE into an EMPTY database. STOP on any error. No DROP/REPLACE.
-- Do not edit DDL or the checksum: it matches the PHP migration catalog.
-- MySQL DDL implicitly commits; backup and inspect on any partial failure.

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

-- Verify AFTER import (in phpMyAdmin SQL tab):
-- SELECT version, status, checksum FROM schema_migrations;
-- SHOW TABLES;
