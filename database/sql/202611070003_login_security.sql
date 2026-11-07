-- OPGateway security foundation: phpMyAdmin SQL update.
-- Do not import unless documented deployment preflight has been reviewed.
-- Uses existing arsamnet_opgateway_db; never recreate or drop existing tables.
-- Creates independent support tables only. Requires a database backup.
-- No changes to the already-imported initial migration ledger.

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
