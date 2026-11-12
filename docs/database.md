# Database, schema and initial administrator

## Connection

The private `.env` supplies `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD`; `PdoConnectionFactory` uses PDO MySQL with native prepared statements. It explicitly selects `utf8mb4_unicode_ci`, matching the immutable migration/table contract even when MySQL 8 defaults to another `utf8mb4` collation. Never use a public database test script or commit secrets.

The current shared host requires `DB_HOST=db`. Local macOS usually uses `DB_HOST=127.0.0.1` and `DB_PORT=3306`, assuming TCP MySQL is running and the configured user has grants. `/health` does not validate DB connectivity.

## New empty database only

Import `database/sql/opgateway_full_schema.sql` **once** into a fresh empty database using phpMyAdmin. On this unreleased branch, verify **15 final tables and 9 completed rows** in `schema_migrations`. It preserves all earlier migration statements and checksums. Migration 005
migrates `smtp_settings` to generic key/value `settings` and then drops only
the old SMTP table. **Never import into an existing populated database, re-run migrations or reset tables to fix a connection error.** The online app does not execute migrations.

For the currently deployed alpha.3 schema there is nothing to import. The feature branch requires 004, 005 and 006 in order. The local development database already completed 005: apply only 006 after a verified backup. Backup before any intentional database operation.

## Google sign-in migration 007 — unreleased

A new table `google_identities` maps each existing user to a unique
case-sensitive Google OIDC subject. Global settings `google.client_id` and
encrypted `google.client_secret` use the existing `settings` table.
Migration 007 itself does not change passwords. No new .env keys are needed.

For existing databases with six completed migrations, back up first and
execute ONLY `database/sql/upgrade_202611110001.sql` (007), once and only
after reviewing its content. Do not run migration 007 during alpha.4 tests.
On a new empty database, this unreleased branch's full schema creates
seven migration records and fourteen final tables. Until migration 007
is completed, its account/admin handlers fail closed.

## Google-first optional-password migration 008 — unreleased

After a verified local backup and only after migration 007, review and execute
`database/sql/upgrade_202611120001.sql` manually. It preserves every existing
hash, makes `users.password_hash` nullable, adds
`users.auth_session_version BIGINT UNSIGNED NOT NULL DEFAULT 1`, and creates
`google_identity_confirmations` for hashed, expiring, one-time collision proof.
It deletes no users and creates no dummy credentials. A fresh install has eight
completed migration records and fifteen final tables.

Do not deploy older seven-migration application code after 008: its readiness
check correctly rejects the unexpected schema. Use the reviewed
008-compatible code rollback described in
[the migration and rollback plan](google-first-password-rollback.md), retaining
nullable hashes and all Google-only users.

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
- Migration 006 (`202611100003_create_password_reset_tokens`) adds a single
  password reset token table. A local database already at 005 requires only
  `database/sql/upgrade_202611100003.sql` after a backup.
- Migration 007 adds the subject-unique Google identity mapping. Migration 008
  adds optional passwords, session versions and confirmation tokens. Apply each
  manual script once, in order, after backup and review.
- Readiness for this branch requires **nine** completed migrations. Never deploy new PHP
  before upgrading the database. MySQL DDL may auto-commit: if execution
  fails partway, stop and inspect; do not simply rerun.
- The original migrations and their checksums are never rewritten.


## Migration 009 — shared user names

The existing `users` table remains the only identity table for owners and administrators. Apply reviewed `database/sql/upgrade_202611120002.sql` only to the existing eight-migration database, after a verified backup, before deploying profile-gated code. Adds nullable first and last name fields; does not modify existing records or roles.
