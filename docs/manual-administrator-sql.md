# Manually create the first administrator in phpMyAdmin

There is no public administrator setup endpoint, setup token or SETUP_*
configuration. This procedure is for the existing database, not schema
installation or recreation. No GitHub workflow, server shell or app script.

## 1. Check the target database

Back up the database in phpMyAdmin. Select the actual `DB_NAME` from your
private .env (this host requires `DB_HOST=db`). Run these read-only checks:

```sql
SELECT
  (SELECT COUNT(*) FROM users) AS users_count,
  (SELECT COUNT(*) FROM roles) AS roles_count,
  (SELECT COUNT(*) FROM user_roles) AS memberships_count;
```

**Proceed only if all counts are zero.** Do not delete or reset existing
accounts to force this procedure.

## 2. Generate a PHP-compatible password hash on your Mac

Use local PHP 8.3; do not use MySQL `PASSWORD()`, MD5, or SHA256 for the
user's password. This command turns off terminal echo while typing. It
prints only a PHP `password_hash()` result:

```bash
php -r 'fwrite(STDERR,"Administrator password: "); system("stty -echo"); try { $p = rtrim(fgets(STDIN), "\r\n"); } finally { system("stty echo"); fwrite(STDERR,"\n"); } if(strlen($p)<12 || strlen($p)>1024) { fwrite(STDERR,"Password must have 12-1024 bytes.\n"); exit(1); } echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'
```

Never paste the plaintext password or hash into issues, chats or the
Git repository. Keep the hash only in the intended database.

## 3. Run exactly once

Replace `admin@example.com` with the actual administrator email
and `REPLACE_WITH_PHP_PASSWORD_HASH` with the full hash above.
If the email contains a single quote, escape it as two single quotes.
Select the intended database in phpMyAdmin:

```sql
START TRANSACTION;

INSERT INTO roles (role_key)
VALUES ('admin');
SET @created_admin_role_id = LAST_INSERT_ID();

INSERT INTO users (
    email,
    password_hash,
    status,
    email_verified_at,
    approved_at,
    created_at,
    updated_at
) VALUES (
    'admin@example.com',
    'REPLACE_WITH_PHP_PASSWORD_HASH',
    'active',
    UTC_TIMESTAMP(6),
    UTC_TIMESTAMP(6),
    UTC_TIMESTAMP(6),
    UTC_TIMESTAMP(6)
);
SET @created_admin_user_id = LAST_INSERT_ID();

INSERT INTO user_roles (user_id, role_id)
VALUES (@created_admin_user_id, @created_admin_role_id);

COMMIT;
```

If any statement fails, **do not retry the entire block**. Inspect the
transaction state and database rows; if still in the same session and
transaction is open, use `ROLLBACK;` before repairing the issue.
Do not run the block twice. phpMyAdmin connections may vary between
submissions; submit this as one batch.

## 4. Verify (read-only)

```sql
SELECT u.id, u.email, u.status, u.email_verified_at,
       u.approved_at, r.role_key
FROM users AS u
JOIN user_roles AS ur ON ur.user_id = u.id
JOIN roles AS r ON r.id = ur.role_id;
```

Expect exactly one active, approved and email-verified user with the
`admin` role. Login uses PHP `password_verify()` against `password_hash`.

The previous HTTP setup endpoint is deleted from the application. Remove
all `SETUP_*` keys from the private hosting `.env` manually; Git cannot
edit this secret file. Admin login is a separate feature controlled by
`ADMIN_UI_ENABLED`, `ADMIN_UI_ALLOWED_IP` and `LOGIN_RATE_LIMIT_KEY`.
