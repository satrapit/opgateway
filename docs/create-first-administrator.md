# Create the first Administrator (operator procedure)

This is a one-time, carefully limited operation for the production
cPanel instance. Existing schema migrations 001, 002 and 003 have been
imported. **Do not run any new SQL schema file.**

The system does not ship a default account or password, and no GitHub
commit can create a user in the production database.

## Safety checks

1. Back up the production database using phpMyAdmin Export.
2. Use read-only SQL in the existing database:
   `SELECT COUNT(*) AS users_count FROM users;`
   `SELECT COUNT(*) AS roles_count FROM roles;`
   `SELECT COUNT(*) AS memberships_count FROM user_roles;`
   All three counts MUST be zero for first setup. If not, stop; never
   delete accounts or bypass the guard.
3. Check `APP_URL=https://opgateway.arsamnet.com` in the private .env.
4. The intended operator must know the exact source IP received by
   PHP as `REMOTE_ADDR`. On proxy/CDN configurations it may be a proxy
   IP, in which case do NOT enable setup until proxy trust is reviewed.
5. Confirm the server reports `HTTPS=on` on the HTTPS virtual host.
   Do not work around the check using X-Forwarded-Proto from clients.
6. Keep admin UI disabled during bootstrap.

## Secure one-time token generation on macOS

Use macOS Terminal only to generate random tokens and their hash; no PHP,
Composer, Docker or MySQL CLI is required. Do not paste any generated
secret or password into this chat, GitHub, or issue trackers.

```bash
setup_token="$(openssl rand -hex 32)"
setup_digest="$(printf '%s' "$setup_token" | xxd -r -p | shasum -a 256 | awk '{print $1}')"
rate_key="$(openssl rand -hex 32)"
printf '%s' "$setup_token" | pbcopy
printf 'SETUP_TOKEN_HASH=%s\nSETUP_RATE_LIMIT_KEY=%s\n' "$setup_digest" "$rate_key"
```

The command copies `setup_token` to the macOS clipboard without printing it.\nPaste it immediately into a private password manager and clear the clipboard\nafterward. It is the token entered in the setup form. The printed digest is safe to
configure but must not be committed. The separate rate key is also
secret and must remain only in the server's private .env.

## Brief opt-in setup window

Edit ONLY `/home/arsamnet/repositories/opgateway/.env` using cPanel
File Manager. Use your own, verified operator IP:

```dotenv
APP_URL=https://opgateway.arsamnet.com
SETUP_ENABLED=true
SETUP_ALLOWED_IP=<verified operator IP>
SETUP_TOKEN_HASH=<sha256 of raw token bytes>
SETUP_RATE_LIMIT_KEY=<independent 32-byte random hex key>
ADMIN_UI_ENABLED=false
```

Open `https://opgateway.arsamnet.com/setup/administrator` over HTTPS
from that exact IP. Enter the intended admin email, a strong unique
password (at least 12 characters), and the one-time setup token.
Submit **once**; do not repeatedly retry failures. A successful response
contains `"status":"administrator_created"` with HTTP 201.

Immediately set `SETUP_ENABLED=false`, clear `SETUP_TOKEN_HASH`
and `SETUP_RATE_LIMIT_KEY`, and verify the installer returns 404.

## Read-only verification

In phpMyAdmin, with the existing database selected, run:

```sql
SELECT id, email, status, email_verified_at, approved_at
FROM users ORDER BY id;
SELECT r.role_key, u.id AS user_id
FROM user_roles AS ur
JOIN users AS u ON u.id = ur.user_id
JOIN roles AS r ON r.id = ur.role_id;
```

One active, approved, verified user with the `admin` role should exist.
Never share password hashes or secret tokens. If anything differs,
STOP and investigate without resetting the database.

Only after successful setup and separate login security checks should
the admin UI be considered for enabling. `/v1` stays blocked.

## Deploy

On MacBook: `git pull --ff-only origin main`.
In cPanel Git Version Control: Update from Remote, then Deploy HEAD
Commit. The standard deployment does not run SQL or touch .htaccess.

No automation workflows, server terminal, remote provider integration or
background process are involved. The installer is disabled by default.
