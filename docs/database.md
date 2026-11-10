# Database, schema and initial administrator

## Connection

The private `.env` supplies `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD`; `PdoConnectionFactory` uses PDO MySQL with native prepared statements. Never use a public database test script or commit secrets.

The current shared host requires `DB_HOST=db`. Local macOS usually uses `DB_HOST=127.0.0.1` and `DB_PORT=3306`, assuming TCP MySQL is running and the configured user has grants. `/health` does not validate DB connectivity.

## New empty database only

Import `database/sql/opgateway_full_schema.sql` **once** into a fresh empty database using phpMyAdmin. On the next feature branch, verify **12 final tables and 5 completed rows** in `schema_migrations`. It preserves all earlier migration statements and checksums. Migration 005
migrates `smtp_settings` to generic key/value `settings` and then drops only
the old SMTP table. **Never import into an existing populated database, re-run migrations or reset tables to fix a connection error.** The online app does not execute migrations.

For the currently deployed alpha.3 schema there is nothing to import. The feature branch requires 004 followed by 005. The local DB already
has 004: apply only 005 to it, after a backup; never re-run 004. Backup before any intentional database operation.

## First administrator — one-time manual SQL

**Only for a fresh instance with no administrator.** Confirm the selected database, take a backup and check:

```sql
SELECT (SELECT COUNT(*) FROM users) AS users_count,
       (SELECT COUNT(*) FROM roles) AS roles_count,
       (SELECT COUNT(*) FROM user_roles) AS memberships_count;
```

The SQL below is only appropriate when **all three counts are zero**. For a populated database, use a separately reviewed procedure instead; do not overwrite or delete identities.

On a trusted local PHP 8.3 terminal, generate the hash using `password_hash` (not MySQL `PASSWORD()`, SHA256 or MD5):

```bash
php -r 'fwrite(STDERR,"Administrator password: "); system("stty -echo"); try { $p = rtrim(fgets(STDIN), "\r\n"); } finally { system("stty echo"); fwrite(STDERR,"\n"); } if(strlen($p)<12 || strlen($p)>1024) { fwrite(STDERR,"Password must have 12-1024 bytes.\n"); exit(1); } echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'
```

In phpMyAdmin, select the empty target DB and execute this batch **once**, replacing the example email and password hash (never share the hash in a chat or commit it):

```sql
START TRANSACTION;

INSERT INTO roles (role_key) VALUES ('admin');
SET @new_admin_role = LAST_INSERT_ID();

INSERT INTO users
  (email, password_hash, status, email_verified_at,
   approved_at, created_at, updated_at)
VALUES
  ('admin@example.com', 'REPLACE_WITH_PHP_PASSWORD_HASH', 'active',
   UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));
SET @new_admin_user = LAST_INSERT_ID();

INSERT INTO user_roles (user_id, role_id)
VALUES (@new_admin_user, @new_admin_role);

COMMIT;
```

If any statement fails, **stop**, inspect the transaction and data; do not blindly replay it (phpMyAdmin sessions may differ). Verify with:

```sql
SELECT u.id, u.email, u.status, u.email_verified_at,
       u.approved_at, r.role_key
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id;
```

Expect one active, verified, approved administrator. There is no HTTP setup route, `SETUP_*` flags or default password.

## Immutable migration history

Migration catalog versions:
- `202611070001_create_identity_tables`
- `202611070002_create_email_verification_tokens`
- `202611070003_create_login_security_tables`

Do not edit existing migration SQL or checksums. The internal migration runner uses an advisory lock and durable ledger, and deliberately refuses unexpected or incomplete history. MySQL DDL does not support fully atomic rollback; it must never run automatically on a shared cPanel deployment.


## Unreleased migrations (manual only)

- 202611100001_create_gateway_integrations: adds signup_source with DEFAULT
  manual, smtp_settings and provider_connections. No existing account is
  automatically promoted or approved.
- On existing populated instances, execute ONLY the reviewed
  database/sql/upgrade_202611100001.sql in a verified, backed-up database
  and only after it passes in a disposable local copy.
- Migration 005 (`202611100002_unify_settings_storage`) creates
  `settings(setting_key, setting_value, updated_at)`; copies seven `smtp.*`
  values, including the **unchanged encrypted password**, and removes the
  old `smtp_settings` table.
- For databases already on 004, execute only
  `database/sql/upgrade_202611100002.sql` once after a verified backup.
  For alpha.3 databases apply both scripts in order.
- Readiness requires **five** completed migrations. Never deploy new PHP
  before upgrading the database. MySQL DDL may auto-commit: if execution
  fails partway, stop and inspect; do not simply rerun.
- The original migrations and their checksums are never rewritten.
