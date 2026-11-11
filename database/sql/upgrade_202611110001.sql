-- Migration 007: existing database must have completed 001-006.
-- Take a verified backup and execute manually, exactly once.
-- Do not run during the alpha.4 production pilot.
SELECT DATABASE() AS selected_database;
SELECT version, status FROM schema_migrations ORDER BY version;
INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611110001_create_google_identities', '668039504a182a0dbb6c178897d901fbd5a1489b7bee31b424e1c70b1d2058c5', 'running', UTC_TIMESTAMP(6));

CREATE TABLE google_identities (
    user_id BIGINT UNSIGNED NOT NULL,
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    google_email VARCHAR(254) NOT NULL,
    linked_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_google_identities_sub (google_sub),
    CONSTRAINT fk_google_identities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_migrations SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611110001_create_google_identities'
AND checksum = '668039504a182a0dbb6c178897d901fbd5a1489b7bee31b424e1c70b1d2058c5' AND status = 'running';
