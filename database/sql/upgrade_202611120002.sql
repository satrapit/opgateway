-- OPGateway additive migration 009: execute ONCE after backing up existing DB.
-- Requires exactly the eight completed migrations through 008.
-- Use phpMyAdmin on the selected OPGateway database; never re-import full schema.
SELECT COUNT(*) AS completed_migrations FROM schema_migrations WHERE status = 'completed';
SELECT COUNT(*) AS users_before FROM users;

INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES ('202611120002_add_user_names',
'd9b28111c0cd22504273e78012e2f817e4780cba29f8e03bd334530a13c77ce6',
'running', UTC_TIMESTAMP(6));

ALTER TABLE users
ADD first_name VARCHAR(100) NULL,
ADD last_name VARCHAR(100) NULL;

UPDATE schema_migrations
SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = '202611120002_add_user_names'
AND checksum = 'd9b28111c0cd22504273e78012e2f817e4780cba29f8e03bd334530a13c77ce6'
AND status = 'running';

SELECT version, status FROM schema_migrations ORDER BY version;
SELECT COUNT(*) AS users_after FROM users;
