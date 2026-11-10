# Administrator browser

## Current routes

| Method | Path | Behavior |
|---|---|---|
| GET | `/admin/login` | Login form with session-bound CSRF |
| POST | `/admin/login` | Validate password, administrator role and rate limits; HTTP 303 to `/admin` |
| GET | `/admin` | Summary cards (counts of bounded visible records) and links to independent pages |
| GET | `/admin/users` | Verified pending users; optional approval forms |
| GET | `/admin/owners` | Approved owners; optional disabled client provisioning |
| GET | `/admin/clients` | Active/disabled API clients; optional activation and IP request forms |
| GET | `/admin/ips/pending` | Pending exact IP allowlist entries and optional approval |
| GET | `/admin/ips/approved` | Approved exact IP entries and optional revocation |
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

Keep `ADMIN_ACTIONS_ENABLED=false` until new tests and real hosting permissions are verified. Enabling the UI alone does **not** enable write actions. The new forms use the same default-off `ADMIN_ACTIONS_ENABLED` switch, and allow only `create_client` and `request_ip` with exact `_csrf`, `action`, `target_id` and `value` fields. The value is a 1–128 byte printable label or one exact IPv4/IPv6 address; arbitrary names, ranges/CIDR and proxy-provided addresses are not accepted. Approved actions use the same CSRF-protected form POST (exact `_csrf`, `action`, `target_id` keys). Form IDs must be positive decimal integers, and extra fields are rejected. Successful Twig admin actions use a fixed Post/Redirect/Get destination based on the allowlisted operation (`/admin/users`, `/admin/owners`, `/admin/clients`, `/admin/ips/pending`, or `/admin/ips/approved`), rather than returning to the Overview. Legacy admin HTML still redirects to `/admin`. The server never accepts a return URL from POST data, Referer, Origin, or query parameters. Denials and throttling retain generic error statuses. SQL state changes and metadata-only security events share a transaction, so an audit write failure rolls back the mutation. The action limiter allows at most 20 per administrator and 40 per source IP within a 15-minute window, using HMAC-isolated counters in existing `login_rate_limits`.

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

## TailAdmin workspace information architecture

The additional `ADMIN_TWIG_UI_ENABLED=true` gate selects an independently
navigable, server-rendered interface (not a single page of anchor jumps).
The six pages are /admin, /admin/users, /admin/owners, /admin/clients,
/admin/ips/pending and /admin/ips/approved. All detailed pages require
the same fresh account and administrator role checks as /admin, after
the trusted HTTPS, exact IP and schema checks. In legacy mode, the five
new GET paths return 404; no new public endpoints exist.

The Overview displays only five bounded counts and deep links, not the
full review tables. Each detailed route shows exactly one authorized
review queue with a responsive card/table presentation. The active nav
item and page title come from a fixed server-side section key (and remain
accurate on refresh, deep links and Back/Forward). The Logout control
is icon-only with an accessible label and still posts the same CSRF form.
Actions and provisioning continue to use the original strict POST
form fields and separate `ADMIN_ACTIONS_ENABLED` gate. Never infer
that a 401 from a logged-out mutation request proves the flag is off:
with the flag disabled an otherwise valid POST should yield 404.

## Accessible record dialogs (progressive enhancement)

On Twig admin section pages, `components/records.html.twig` adds a native
`<details>` summary for each authorized row/card. This remains usable when
JavaScript is unavailable. The local `admin-dialogs.js` module upgrades the
summary to a native `<dialog>` using cloned, already escaped DOM content
(no fetch, `innerHTML`, arbitrary URL-driven template, secrets, or extra
backend queries).

Authorized `create_client` and `request_ip` forms remain real
`POST /admin/provision` elements with exactly `_csrf`, `action`,
`target_id`, `value`. JavaScript replaces each inline form with an
action launcher only after initialization, then moves the **same form**
node into the dialog. Without JavaScript, the form stays inline.
Client creation remains disabled by default until separately activated;
requested IP entries remain pending until approved. No new status data
or privileged details are exposed by the dialog.

`disable_client` and `revoke_ip` use a native confirmation dialog
showing the escaped target label/IP before re-submitting the **same**
`POST /admin/review` form with its original CSRF and fields. Escape
and Cancel never submit. Fallback uses the browser confirmation
dialog; without JS the server-side CSRF and authorization checks still
apply, though there is no client-side confirmation. After an authorized successful Twig action, the server returns HTTP 303 to that operation's corresponding section. Denied actions retain the existing error response; there is no fabricated success toast.
`ADMIN_ACTIONS_ENABLED=false` must remain in production and local
read-only review until explicit administrative-action tests.

## Compact per-record actions and modal dismissal

The sidebar logo header and main page header both have fixed `h-20`
(80px) to align their bottom borders at desktop and mobile sizes.
Each data record now offers a 44px, icon-only, titled vertical-ellipsis
native `<details>` options control on desktop **and** mobile. Its
content contains existing record details and (when allowed) the original
POST form actions. It is not assigned `role="menu"` because its forms
and nested `<details>` have ordinary document semantics. With JS, only
one menu remains open, pointer interaction outside closes it and Escape
closes it with focus restoration. Without JS, the native `<details>`
remains functional. Desktop rows no longer need space for large inline
action forms; mobile buttons float near the status label.

All three modal dialogs also close if a pointer interaction begins and
ends on the **backdrop outside the dialog's rectangle**; clicking inside
never dismisses a form. Cancel, Escape and backdrop dismissal never POST.
Themes use the existing self-hosted styles; menu and dialog entry
transitions are 130–160ms with full reduced-motion support.

The fixed `AdminMutationRedirect` mapping applies **only after** an
authorized action succeeds and only for the Twig UI. It does not
change rate limits, CSRF, source IP, admin role checks or failure
response semantics. No arbitrary `return_to` field is accepted.
