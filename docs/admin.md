# Administrator browser

## Current routes

| Method | Path | Behavior |
|---|---|---|
| GET | `/admin/login` | Login form with session-bound CSRF |
| POST | `/admin/login` | Validate password, administrator role and rate limits; HTTP 303 to `/admin` |
| GET | `/admin` | Authenticated review queues; buttons appear only when actions enabled |
| POST | `/admin/review` | Approve user/client/IP, disable an active client, or revoke an IP; separately enabled |
| POST | `/admin/provision` | Create a disabled client or request an unapproved IP; separately enabled |
| POST | `/admin/logout` | Validate CSRF and session; HTTP 303 to `/admin/login` |

The dashboard shows up to 50 verified pending users, up to 50 active/verified/approved owners, up to 50 disabled or active clients belonging to eligible owners, and up to 50 pending IP entries and up to 50 approved IP entries, including entries belonging to disabled clients so administrators can revoke them before reactivation. Only an already authenticated, freshly authorized administrator can read these records. No credentials or password hashes are queried. The review listings remain read-only queries; write actions require a separate POST, authorization and audit.

State-changing approval actions are exposed **only** through `POST /admin/review` when separately enabled. Supported operations are `approve_user` (verified pending user), `activate_client` (eligible owner and inactive client), `approve_ip` (eligible active client and pending IP), `disable_client` (make existing credentials unusable), and `revoke_ip` (remove the exact allowlist entry). The separate `POST /admin/provision` route can create a **disabled** client for an eligible owner or request an exact IPv4/IPv6 entry for an eligible client; newly requested IP entries are **disabled** until approved. Both routes require a freshly authorized administrator, strictly parsed form fields, CSRF, rate limiting and transactional metadata-only audit. Public signup, owner-initiated client creation, and the provider proxy remain disabled. Owner-only credential issuance is separately gated under `/account`, not administered through these routes. No `/setup/administrator` route exists.

## Required private .env

```dotenv
APP_URL=https://opgateway.example.com
ADMIN_UI_ENABLED=false
ADMIN_ACTIONS_ENABLED=false
ADMIN_UI_ALLOWED_IP=
LOGIN_RATE_LIMIT_KEY=
```

Keep `ADMIN_ACTIONS_ENABLED=false` until new tests and real hosting permissions are verified. Enabling the UI alone does **not** enable write actions. The new forms use the same default-off `ADMIN_ACTIONS_ENABLED` switch, and allow only `create_client` and `request_ip` with exact `_csrf`, `action`, `target_id` and `value` fields. The value is a 1–128 byte printable label or one exact IPv4/IPv6 address; arbitrary names, ranges/CIDR and proxy-provided addresses are not accepted. Approved actions use the same CSRF-protected form POST (exact `_csrf`, `action`, `target_id` keys). Form IDs must be positive decimal integers, and extra fields are rejected. Successful actions redirect to `/admin` without exposing resource details; denials and throttling return generic errors. SQL state changes and metadata-only security events share a transaction, so an audit write failure rolls back the mutation. The action limiter allows at most 20 per administrator and 40 per source IP within a 15-minute window, using HMAC-isolated counters in existing `login_rate_limits`.

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

## End-to-end onboarding test (local database only)

1. Sign in as an administrator and verify the newly approved test user appears under **Approved account owners**.
2. Set `ADMIN_ACTIONS_ENABLED=true` in the **local** private `.env` only, then enter a label next to that owner and click **Create client**. The new record must have `status='disabled'`.
3. Under **API clients and IP provisioning**, click **Activate client**. It must become `active`.
4. Use **Request IP** to submit a single exact test IPv4/IPv6 address. The new whitelist row must have `enabled=0` and `approved_at=NULL`.
5. Under **Pending IP allowlist requests**, click **Approve IP**. It must become `enabled=1` and receive `approved_at`.
6. Use **Disable client** and **Revoke IP** on test records to confirm emergency access removal. Disabling a client makes its bearer credentials unusable even when a key is not revoked; removing an approved IP immediately denies requests from that source. Do not use real production client records for this destructive test.
7. Verify successful audit event codes `admin_create_client_allowed`, `admin_activate_client_allowed`, `admin_request_ip_allowed`, `admin_approve_ip_allowed`, `admin_disable_client_allowed`, `admin_revoke_ip_allowed` in private `security_events`. Do not place credentials or full request bodies in logs.

Client credential generation and user self-service remain a separate security-sensitive step; no API bearer is issued by these administrator operations. Keep production `ADMIN_ACTIONS_ENABLED=false` until the new sequence is validated with local test data. No migration is required.

## Diagnostics

- 404 for admin login: enabled flag absent/false, non-HTTPS request or IP mismatch.
- 503: configuration, PDO connection, schema readiness or secure-session failure. Consult the private `storage/logs/opgateway.jsonl` event.
- MySQL error 2002: verify the local host/socket/port; macOS `DB_HOST=localhost` may select a missing Unix socket while `127.0.0.1` uses TCP.
- Incorrect password or missing admin role: the login form shows a generic failure.

For a fresh database, use the one-time administrator SQL in [database.md](database.md). Do **not** rerun provisioning on an existing administrator.

## Twig / TailAdmin Free admin interface (work in review branch)

Legacy `BrowserViews` remains the default. Only `ADMIN_TWIG_UI_ENABLED=true` selects the parallel `TwigBrowserViews` facade through the unchanged adapter contracts, rendering private Twig templates. `TwigRenderer` requires an installed, pinned Twig 3 runtime. Admin login, overview, original bounded queues and existing strict POST forms use the same routes and CSRF names. This new UI gate is **false** by default and independent of `ADMIN_UI_ENABLED` and `ADMIN_ACTIONS_ENABLED`. The summary widgets count **records in each limited result**, never all records in MySQL. Actions are only rendered if `ADMIN_ACTIONS_ENABLED` was already enabled and authorization remains enforced in the adapter/domain services.

Mobile navigation links to anchored sections on the existing `GET /admin` route; there are no new operational endpoints. Built assets are loaded from `/assets/admin.css`, `/assets/theme-init.js` and `/assets/admin.js`. Theme preference (`system`, `light`, `dark`) is the **only** browser-stored UI setting. The Alpine CSP runtime and lightweight interactions never access API credentials. See `docs/frontend-architecture.md` and `docs/ui-design-system.md`.

**Deployment gate:** Do not merge/deploy the source-only branch before explicitly setting `ADMIN_TWIG_UI_ENABLED=true`, and before `composer.lock`, `frontend/package-lock.json`, complete built assets, local PHP/JS tests, real browser/CSP checks, and private vendor provisioning have passed. In particular, do not enable any disabled feature flags as part of redesign.
