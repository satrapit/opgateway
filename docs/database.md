# Database foundation — migration planning

## cPanel environment

This deployment uses a database named `arsamnet_opgateway_db` and a dedicated user named `arsamnet_opgateway_user`. These names are identifiers, not credentials. Only the private `/home/arsamnet/repositories/opgateway/.env` holds the database password. Assign the user to the database in cPanel MySQL Database Wizard. Do not paste credentials into issues, logs, commits or ChatGPT.

Configuration keys: `DB_HOST=localhost`, `DB_PORT=3306`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.

`DatabaseConfig` validates the settings and `PdoConnectionFactory` constructs an on-demand MySQL PDO connection with native prepared statements. The public health endpoint does not connect to the database.

## Initial schema (defined, not installed)

The first SQL migration is `202611070001_create_identity_tables`. It defines:

- `users`: unique email, password hash, pending account status, verification and approval timestamps.
- `roles` and `user_roles`: separate roles and user membership.
- `api_clients`: distinct applications owned by a user; disabled by default.
- `api_credentials`: credential prefix and securely hashed secret, expiry and revocation.
- `client_ip_whitelists`: binary IPv4/IPv6 addresses; disabled until approved.

All tables use InnoDB and `utf8mb4`, with foreign keys and uniqueness where needed. No default users, administrator accounts, API credentials, IP addresses or upstream permissions are inserted. Other required tables (permissions, audit, operations, resource ownership) will be introduced in reviewed subsequent migrations.

## Safety and execution

**The migrations are definitions only: no database migration has been executed and no web installer is exposed.** Never connect schema changes to ordinary `/health` requests or the cPanel deployment task.

The future protected installer will need a one-time bootstrap secret, strict HTTPS, rate limits, an authenticated operator session, explicit confirmation, a migration ledger, a database advisory lock and an irreversible installation lock. Operators must take a database backup before schema changes.

MySQL/MariaDB DDL may implicitly commit and a partial failure requires an explicit recovery procedure. Migration version history alone does not guarantee transactional atomicity. The migration engine must refuse to proceed on uncertain or partially applied states; it must never automatically reset tables.

## Testing

Run `php scripts/lint.php` and `composer test` locally. Migration tests inspect schema definitions without needing MySQL or production credentials. Database integration tests against a disposable local database must precede any production execution.
