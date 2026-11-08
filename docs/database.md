# Database foundation

## Current cPanel deployment

- DB name: `arsamnet_opgateway_db`
- DB user: `arsamnet_opgateway_user`
- Private configuration: `/home/arsamnet/repositories/opgateway/.env`
- Administration: cPanel + phpMyAdmin (no hosting Terminal)

Do not put a database password or other secrets in the repository, public
Document Root, error logs or support messages.

The single Medoo connection factory uses PDO internally. Existing transactional repositories reuse the same PDO handle. `/health` does not connect to
the database; a healthy response alone does not prove database connectivity.

## First migration

The canonical migration version is
`202611070001_create_identity_tables`.
Import `database/sql/202611070001_create_identity_tables.sql` into an
**empty** `arsamnet_opgateway_db` through phpMyAdmin, following
[the manual installation procedure](phpmyadmin-install.md).

The SQL file mirrors the six `CreateIdentityTables` statements and creates
a matching `schema_migrations` record, so future MigrationRunner executions
recognize the migration as completed instead of trying to create the same
tables again. No users, API keys, admin account or whitelisted IPs are inserted.

## Future changes

Future schema revisions must be provided as separately versioned,
non-destructive SQL imports and documented phpMyAdmin operations unless a
properly authenticated one-time web installer is implemented. No automated
migrations in cPanel Git deployments. Preserve the ledger; do not modify
previous SQL files or checksums after release.

MySQL DDL can implicitly commit. Keep backups, verify imports and never
blindly rerun a partially failed migration.
