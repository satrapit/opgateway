# Database foundation — design stage

## Runtime and credentials

The target is MySQL or MariaDB under cPanel with PHP 8.3 and `pdo_mysql`.
Create the database and dedicated user through cPanel Database Wizard.
Only the private `/home/arsamnet/repositories/opgateway/.env` may contain
database credentials. Never commit or paste real credentials.

`DatabaseConfig` validates connection parameters and builds an `utf8mb4`
DSN. `PdoConnectionFactory` uses native prepared statements, no persistent
connections and exception-based PDO handling. It connects only when explicitly
called, never as a side effect of `/health`.

## Migration strategy — not implemented yet

Do **not** execute migrations during a normal HTTP request or cPanel deploy.
A future operator-only web installer must use a one-time out-of-band bootstrap
credential, HTTPS, CSRF protection, session authentication, explicit confirmation,
concurrency locks, a migration ledger and a post-install disable switch.
It must reject arbitrary SQL from request input and never expose database
errors or secrets. cPanel shared hosting cannot assume Terminal access.

MySQL/MariaDB DDL may implicitly commit; backup and idempotence checks are
required before migrations run. Do not claim transactional DDL rollback.

## Planned schema

Initial entities: `schema_migrations`, `users`, `roles`, `user_roles`,
`permissions`, `role_permissions`, `api_clients`, `api_credentials`,
`client_ip_whitelists`, `client_permissions`, `security_events`,
`audit_events`, `resource_ownership`, `operations` and
`operation_attempts`. Enforce foreign keys and uniqueness. Store credentials
as appropriate hashes; resolve IP addresses safely; reject cross-tenant
operations without independently verified ownership.

**No schema migration, web installer or database connection has been executed.**
