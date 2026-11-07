<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/**
 * Persistent throttling counters and metadata-only security event ledger.
 *
 * The ordered SQL statements must match the phpMyAdmin artifact exactly.
 * Never change deployed DDL, the migration version, or its checksum.
 */
final class CreateLoginSecurityTables implements Migration
{
    public function version(): string
    {
        return '202611070003_create_login_security_tables';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE login_rate_limits (
    bucket_hash BINARY(32) NOT NULL,
    window_number BIGINT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_hash, window_number),
    KEY idx_login_rate_limits_window (window_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_code VARCHAR(40) NOT NULL,
    request_id CHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_security_events_created (created_at),
    KEY idx_security_events_code_created (event_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
