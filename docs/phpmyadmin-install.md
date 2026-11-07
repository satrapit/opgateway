# Fresh OPGateway database installation in cPanel / phpMyAdmin

The ONLY repository SQL import for a clean installation is
`database/sql/opgateway_full_schema.sql`. It consolidates all three
immutable PHP migrations and registers their existing matching checksums.

## Before a rebuild

This is NOT a repair for an HTTP 503 with a PDO database error.
Verify database credentials, grants, DB host/port and PHP `pdo_mysql`
before replacing an already-created database. A database may be perfectly
intact while the PHP process cannot connect to it.

If a full reinstall is intentionally required, export a verified backup
of `arsamnet_opgateway_db` from phpMyAdmin. Record any data that must
be retained; rebuilding loses existing accounts, roles, client records,
API keys and tokens. Disable the installer and admin UI in the private
`.env` before altering schema. Ensure the target database is EMPTY
using cPanel / phpMyAdmin; the SQL does not drop any existing tables.

## Import

1. In cPanel > Manage My Databases, prepare the intended database
   and assign the existing MySQL user with required privileges.
2. Select the EMPTY target database in phpMyAdmin.
3. Import `database/sql/opgateway_full_schema.sql` once.
   Do NOT import into the existing 10-table database.
4. Inspect ALL SQL messages. Stop on any failure; MySQL DDL is
   not completely rollback-safe, so do not blindly re-import.
5. Verify 10 tables, three rows in `schema_migrations` all
   `completed`, and empty `users`, `roles` and `user_roles`.

Expected immutable migration checksums:
- 001 `fee0c5fbfd548e1cddb985befd9bd8a9a10ffb5b5255ac767b0432ca9e12c1a0`
- 002 `0bc2e4471188fb379f737af925ebce4dcfc00537ef7b7992b17d2153b57e7727`
- 003 `a2932ddac794ac00993a4077dd67d7ca4dd94ec9df971d81cf15b4518342d070`

## Connection after installation

Existing private configuration remains at
`/home/arsamnet/repositories/opgateway/.env`.
Verify `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
`DB_PASSWORD`, and MySQL user permissions separately.
Never commit credentials or upload .env to the public Document Root.

No user/admin account is seeded. The first administrator must be
created through the separately protected setup process when safe.

MacBook: Git only (`git pull --ff-only origin main`).
cPanel: Git Version Control > Update from Remote / Deploy HEAD Commit.
Deploying code does not execute this SQL. Do not use GitHub workflows
or hosting terminal.
