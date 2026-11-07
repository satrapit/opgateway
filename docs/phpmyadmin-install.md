# Manual first-time database setup using phpMyAdmin

## Hosting constraints

Target hosting offers cPanel Git Version Control, File Manager, MultiPHP INI
Editor and phpMyAdmin. It has **no Terminal or Docker**. Developers using the
Mac in this workflow need only run Git commands. There is no need to run
MySQL or PHP CLI on either machine.

## Before import

1. Confirm cPanel → Manage My Databases includes database
   `arsamnet_opgateway_db`, associated user `arsamnet_opgateway_user`,
   and suitable permissions.
2. Put the user password only in the server's private `.env`:
   `DB_HOST=localhost`, `DB_PORT=3306`,
   `DB_NAME=arsamnet_opgateway_db`, `DB_USER=arsamnet_opgateway_user`,
   and `DB_PASSWORD` (real password; do not share it).
3. In cPanel → phpMyAdmin, explicitly select **arsamnet_opgateway_db**
   in the left navigation. Confirm it is EMPTY; if any tables already exist,
   **stop**, inspect and do not run the import.
4. Export a backup before importing if there is any existing data.
5. Download `database/sql/202611070001_create_identity_tables.sql` from
   the repository. Import this exact SQL file under phpMyAdmin → Import.
   Do not edit its version or checksum.

## Expected result

Seven tables: `schema_migrations`, `users`, `roles`, `user_roles`,
`api_clients`, `api_credentials`, `client_ip_whitelists`.
There are **no seeded users, roles, API keys or trusted IP addresses**.

In phpMyAdmin's SQL tab run:

```sql
SELECT version, status, checksum FROM schema_migrations;
SHOW TABLES;
```

Expected ledger row: version `202611070001_create_identity_tables`,
status `completed`, checksum
`fee0c5fbfd548e1cddb985befd9bd8a9a10ffb5b5255ac767b0432ca9e12c1a0`.

**Import exactly once**. A partial failure may leave tables and a
`running` ledger row. MySQL DDL cannot be reliably rolled back in an
import. If any statement errors or final status is not `completed`, STOP,
save the error and request an explicit recovery plan; **do not rerun, DROP
tables or manually change the ledger**. phpMyAdmin may continue processing
after some SQL errors, so check both the import messages and schema integrity.

## Deployment separation

Database import is deliberately **manual**. The `.cpanel.yml` Git deployment
must never auto-run DDL or modify database state. This avoids destructive
side effects on production and protects cPanel-managed PHP configuration.
An authenticated operator-only web migration UI may be introduced later
with a controlled preflight and one-time setup lock. No public installer exists.

The included Docker integration test is optional for outside contributors;
it is NOT a requirement or step for this hosting installation.
