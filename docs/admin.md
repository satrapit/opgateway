# Administrator browser

## Current routes

| Method | Path | Behavior |
|---|---|---|
| GET | `/admin/login` | Login form with session-bound CSRF |
| POST | `/admin/login` | Validate password, administrator role and rate limits; HTTP 303 to `/admin` |
| GET | `/admin` | Authenticated administrator landing page |
| POST | `/admin/logout` | Validate CSRF and session; HTTP 303 to `/admin/login` |

State-changing `AdminActions` are **not exposed** as HTTP routes. Public signup, client management and provider proxy remain disabled. No `/setup/administrator` route exists.

## Required private .env

```dotenv
APP_URL=https://opgateway.example.com
ADMIN_UI_ENABLED=false
ADMIN_UI_ALLOWED_IP=
LOGIN_RATE_LIMIT_KEY=
```

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
6. Browser session and role checks do not authorize calls to internal management classes by themselves. Future mutations need separate per-action validation, rate limits and audit.

**Never store plaintext credentials, CSRF tokens, session cookies or request bodies in logs.** Never infer live server behavior from PHPUnit alone; test locally and through the actual HTTPS domain.

## Diagnostics

- 404 for admin login: enabled flag absent/false, non-HTTPS request or IP mismatch.
- 503: configuration, PDO connection, schema readiness or secure-session failure. Consult the private `storage/logs/opgateway.jsonl` event.
- MySQL error 2002: verify the local host/socket/port; macOS `DB_HOST=localhost` may select a missing Unix socket while `127.0.0.1` uses TCP.
- Incorrect password or missing admin role: the login form shows a generic failure.

For a fresh database, use the one-time administrator SQL in [database.md](database.md). Do **not** rerun provisioning on an existing administrator.
