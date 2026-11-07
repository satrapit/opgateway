-- OPGateway versioned phpMyAdmin import.
-- Migration: 202611070003_create_login_security_tables
-- IMPORTANT: This changes only the schema when manually imported.
-- No automatic deployment, DROP, ALTER of existing tables or seeded secrets.
-- STOP on any SQL error. MySQL DDL is NOT transactionally rollback-safe.
--
-- Prerequisites: select the correct database; take a complete backup.
-- Prior migrations 001 and 002 must both be completed before import.
-- login_rate_limits and security_events must not already exist.
-- Do NOT import if any prerequisite is uncertain. Never retry blindly.
-- SHA-256 checksum is derived from JSON-encoded ordered PHP DDL statements.

INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES (
  '202611070003_create_login_security_tables',
  'a2932ddac794ac00993a4077dd67d7ca4dd94ec9df971d81cf15b4518342d070',
  'running',
  UTC_TIMESTAMP(6)
);

CREATE TABLE login_rate_limits (
    bucket_hash BINARY(32) NOT NULL,
    window_number BIGINT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_hash, window_number),
    KEY idx_login_rate_limits_window (window_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_code VARCHAR(40) NOT NULL,
    request_id CHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_security_events_created (created_at),
    KEY idx_security_events_code_created (event_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations
SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611070003_create_login_security_tables'
  AND checksum = 'a2932ddac794ac00993a4077dd67d7ca4dd94ec9df971d81cf15b4518342d070'
  AND status = 'running';

-- Check status and table presence only after a successful, planned import.
-- SELECT version, checksum, status FROM schema_migrations ORDER BY version;
-- SHOW TABLES;
