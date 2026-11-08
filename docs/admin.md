# Administrator browser

## Current routes

| Method | Path | Behavior |
|---|---|---|
| GET | `/admin/login` | Login form with session-bound CSRF |
| POST | `/admin/login` | Validate password, administrator role and rate limits; HTTP 303 to `/admin` |
| GET | `/admin` | Authenticated review queues; buttons appear only when actions enabled |
| POST | `/admin/review` | Three allowlisted management actions, only when `ADMIN_ACTIONS_ENABLED=true` |
| POST | `/admin/logout` | Validate CSRF and session; HTTP 303 to `/admin/login` |

The dashboard displays up to 50 records per queue for verified pending users, inactive clients with eligible owners, and pending IP entries for eligible active clients. Only an already authenticated, freshly authorized administrator can read these records. No credentials or password hashes are queried. The review listings remain read-only queries; write actions require a separate POST, authorization and audit.

State-changing `AdminActions` are exposed **only** through `POST /admin/review` when separately enabled. Supported operations are `approve_user` (verified pending user), `activate_client` (eligible owner and inactive client) and `approve_ip` (eligible active client and pending IP). Public signup, self-service client management and provider proxy remain disabled. No `/setup/administrator` route exists.

## Required private .env

```dotenv
APP_URL=https://opgateway.example.com
ADMIN_UI_ENABLED=false
ADMIN_ACTIONS_ENABLED=false
ADMIN_UI_ALLOWED_IP=
LOGIN_RATE_LIMIT_KEY=
```

Keep `ADMIN_ACTIONS_ENABLED=false` until new tests and real hosting permissions are verified. Enabling the UI alone does **not** enable write actions. Approved actions use the same CSRF-protected form POST (exact `_csrf`, `action`, `target_id` keys). Form IDs must be positive decimal integers, and extra fields are rejected. Successful actions redirect to `/admin` without exposing resource details; denials and throttling return generic errors. SQL state changes and metadata-only security events share a transaction, so an audit write failure rolls back the mutation. The action limiter allows at most 20 per administrator and 40 per source IP within a 15-minute window, using HMAC-isolated counters in existing `login_rate_limits`.

Set `ADMIN_UI_ENABLED=true` only after the administrator is provisioned. `APP_URL` must be an HTTPS origin with no trailing slash. `ADMIN_UI_ALLOWED_IP` must equal PHP's trusted `REMOTE_ADDR`, **not** an unverified `X-Forwarded-For` value. This is one exact IPv4 or IPv6 address, not a CIDR range.

Generate `LOGIN_RATE_LIMIT_KEY` once with `openssl rand -hex 32` on a trusted local system; store the resulting 64 lowercase hexadecimal characters only in private `.env`. Never use the main `APP_KEY` as the limiter key.

**Production**: `APP_URL=https://opgateway.arsamnet.com`; `DB_HOST=db` on the current provider.

**Local**: `APP_URL=https://opgateway.test`; `APP_ENV=local`; `DB_HOST=127.0.0.1` if the local MySQL TCP server listens there. A Valet request may reach PHP from `127.0.0.1` or `::1`: configure the **observed** address, never disable the IP check to work around this.

## Security boundary

1. The browser routes return 404 unless the enable flag, HTTPS and exact allowed IP check pass.
2. The app checks the three immutable migration revisions before starting the secure PHP session.
3. Session cookies use Secure, HttpOnly and SameSite=Strict. The authenticated session is 30 minutes absolute, with fresh DB account/role checks for privileged pages.
4. HTML POSTs require a server-issued, session-bound CSRF token and strictly form-encoded fields. The browser `Origin` header is not considered authoritative on this hosting environment (legitimate browsers may emit `Origin: null`); **CSRF, HTTPS and exact IP filtering remain mandatory**.
5. Failed logins use a generic error. Per-IP and per-identity MySQL-backed rate limits reserve attempts before password verification; metadata-only security events are written.
6. Three explicit administrative review mutations have a **separate default-off switch**, database-checked authorization, strict form parsing, per-admin and per-IP rate limits, and transactional audit. No other management operation is routed.

**Never store plaintext credentials, CSRF tokens, session cookies or request bodies in logs.** Never infer live server behavior from PHPUnit alone; test locally and through the actual HTTPS domain.

## Diagnostics

- 404 for admin login: enabled flag absent/false, non-HTTPS request or IP mismatch.
- 503: configuration, PDO connection, schema readiness or secure-session failure. Consult the private `storage/logs/opgateway.jsonl` event.
- MySQL error 2002: verify the local host/socket/port; macOS `DB_HOST=localhost` may select a missing Unix socket while `127.0.0.1` uses TCP.
- Incorrect password or missing admin role: the login form shows a generic failure.

For a fresh database, use the one-time administrator SQL in [database.md](database.md). Do **not** rerun provisioning on an existing administrator.
