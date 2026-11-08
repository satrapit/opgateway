# Migration runner — offline foundation

The first migration definitions and the `MigrationRunner` exist in the private source tree. **There is no public installer route, no automatic migration on deploy or /health, and no production SQL has run.**

## Security guarantees

- Requires a MySQL PDO driver and a fixed, reviewed migration catalog.
- Obtains a database advisory lock with `GET_LOCK` to prevent overlapping runs.
- Creates an explicit `schema_migrations` ledger with SHA-256 checksums.
- Refuses unknown or modified historical migrations, duplicate versions and incomplete runs.
- Writes a durable `running` marker **before** running schema DDL and marks `completed` only after successful execution.
- A partially applied DDL migration is deliberately not retried automatically.

## Remaining prerequisites

Never expose the MigrationRunner as a web route. Migrations are executed
manually through phpMyAdmin after backup and review; the runner remains
an internal development tool. Do not invoke it from user-facing routes.

Test with an empty disposable local MySQL/MariaDB database. Verify repeated no-op execution, lock contention, interrupted DDL detection, catalog modifications, and backup/restore procedures before production. MySQL DDL implicitly commits: migration markers cannot guarantee atomic rollback.

## Hosting identifiers

The cPanel database is `arsamnet_opgateway_db` and its designated account is `arsamnet_opgateway_user`. Their password is only in private `.env`. Ensure the account is associated with the database and has approved DDL privileges; never store credentials in Git.
