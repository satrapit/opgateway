-- OPGateway read-only preflight: safe to execute in phpMyAdmin.
-- Select arsamnet_opgateway_db first.
-- The original 7 tables and migration 001 are ALREADY installed.
-- This file DOES NOT create, drop, or modify any database object.

SELECT DATABASE() AS selected_database;

SELECT version, status, checksum, completed_at
FROM schema_migrations
ORDER BY version;

SELECT
    TABLE_NAME,
    ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'schema_migrations', 'users', 'roles', 'user_roles',
    'api_clients', 'api_credentials', 'client_ip_whitelists',
    'email_verification_tokens', 'login_rate_limits', 'security_events'
  )
ORDER BY TABLE_NAME;

SELECT
    (SELECT COUNT(*) FROM users) AS users_count,
    (SELECT COUNT(*) FROM roles) AS roles_count,
    (SELECT COUNT(*) FROM user_roles) AS role_memberships_count;
