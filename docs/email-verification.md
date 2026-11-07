# Email verification foundation

This release defines a 256-bit random token generator and a private
database table for email ownership verification. No public verification
endpoint or mail sender exists yet.

## Security constraints

- Generate tokens from `random_bytes(32)`, formatted as lowercase hex.
- Store only `SHA-256` token hashes in `email_verification_tokens`.
- A future service must use a strict 30-minute expiry, atomic single-use
  consumption, throttling and a verified-user state transition.
- `users.email_verified_at` is updated only after valid, unexpired token
  consumption; verification must never automatically approve an account.
- The eventual delivery system must avoid token leakage through URL logs,
  referrer headers, query analytics or email template debugging.
- Never expose raw tokens in API responses or server logs.
- All `/v1` routes remain fail-closed.

## Shared cPanel deployment

This instance uses phpMyAdmin with no server shell, cron or Docker.
Database migrations are **not** executed automatically by Git deployment.
To add this table, first confirm that the initial seven-table schema is
installed and `schema_migrations` version
`202611070001_create_identity_tables` is `completed`.

Then import `database/sql/202611070002_create_email_verification_tokens.sql`
**exactly once** into `arsamnet_opgateway_db`. Backup first. Do not
re-run after a failure because MySQL DDL can implicitly commit.
Run `SELECT version, status, checksum FROM schema_migrations ORDER BY version;`
and check for two `completed` rows and the new
`email_verification_tokens` table.

The SQL and PHP migration share a checksum
`0bc2e4471188fb379f737af925ebce4dcfc00537ef7b7992b17d2153b57e7727`. Never edit this migration after import.
