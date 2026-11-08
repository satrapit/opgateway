# First administrator installation: secure rollout

The first-admin installer exists at `/setup/administrator`, but **is
disabled by default**. Installation never runs automatically on deploy.

## Prerequisites

- The three reviewed migrations are already installed in the existing
  production database. Do not re-import or recreate any tables.
- PHP must receive trusted `HTTPS=on` and a direct `REMOTE_ADDR`; the
  application deliberately ignores client-supplied forwarding headers.
- The source IP must exactly match `SETUP_ALLOWED_IP` in private config.
- `users`, `roles` and `user_roles` must be empty. If not empty,
  do not delete or reset accounts. Setup aborts safely.
- The three migration ledger rows and checksums must pass read-only
  `SchemaReadiness` checks.

## Security controls

- Session-bound CSRF, exact source-IP allowlist, trusted HTTPS, explicit
  one-time setup token, bounded form body and fields. Browser Origin headers
  are not relied upon because hosting/browser referrer policies can change them.
- Setup token is 32 cryptographically random bytes, supplied as 64 lowercase
  hex characters; store only SHA-256 of the **raw decoded bytes** in private
  `SETUP_TOKEN_HASH`. Do not confuse this with SHA-256 of the hex text.
- `SETUP_RATE_LIMIT_KEY` contains a **different** 32-byte random secret
  encoded as 64 lowercase hex characters.
- Up to five POST attempts per 15-minute fixed window, counted in the
  already-existing `login_rate_limits` table before validating the setup
  token. IPs are stored only as HMAC-derived buckets. MySQL errors block setup.
- First user, admin role and role membership are written transactionally.
  A MySQL named lock prevents concurrent setup attempts from racing.
- Unknown or failed setup attempts receive generic responses.

## Private configuration

In `/home/arsamnet/repositories/opgateway/.env`:

```dotenv
SETUP_ENABLED=false
SETUP_ALLOWED_IP=
SETUP_TOKEN_HASH=
SETUP_RATE_LIMIT_KEY=
```

Keep `SETUP_ENABLED=false` until a deliberate, time-limited installation
window. Never include real tokens, hashes, keys, IP addresses, passwords
or account identifiers in the Git repository.

After operator verification, temporarily enable the route, complete setup
from the allowlisted IP, then immediately disable setup and clear
`SETUP_TOKEN_HASH` and `SETUP_RATE_LIMIT_KEY`. Recheck `/setup/administrator`
responds 404. The empty-database rule prevents a second setup.

## Operational steps on this cPanel hosting

1. On the MacBook, use Git only:
   `git pull --ff-only origin main` and inspect `git status --short --branch`.
2. In cPanel Git Version Control, update and deploy the current HEAD.
   The deployment copies only `public/index.php` and does not modify
   cPanel-managed `.htaccess`.
3. Keep setup disabled and verify:
   `/health` returns 200, `/setup/administrator` returns 404,
   `/admin/login` returns 404 and `/v1` returns 503.
4. Before enabling anything, independently test and confirm the server's
   PHP HTTPS and source IP metadata; never bypass the security checks.
5. Once these conditions are verified, create the one-time random token
   and an independent random throttle key. Use a trusted password manager's
   cryptographic generator; do not send either secret in chat.
6. Temporarily set the private config values, perform first setup, and
   immediately reset the switch to false and remove sensitive values.

## Limitations and next verification

Do not assume installation worked merely because the code deployed.
The new MySQL-backed setup throttle and full browser request flow have not
been exercised in an actual hosted integration test. The form uses inline
HTML, and strict CSP intentionally blocks third-party scripts. The login
interface stays off until the administrator has been provisioned and verified.

The installer is operator-only, not a public registration or account recovery
path. Never reset the production database to make setup pass.
