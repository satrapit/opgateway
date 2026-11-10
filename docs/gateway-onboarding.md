# OPGateway: self-service onboarding, SMTP and upstream API

## Release status

This is an unreleased, default-off feature branch. **Do not merge or deploy**
until Composer, schema migration, tests and integration review are complete.
Existing production is running alpha.3. Do not re-import its base schema.

## Four independent security identities

1. Gateway *user*: email/password with PHP password_hash. Browser login
   may originate from any IP over HTTPS, with CSRF, session, throttling and
   fresh account/administrator-role checks.
2. Gateway *administrator*: eligible user with the existing admin role.
   This is the only privileged administrative role required for the project,
   including SMTP settings and user/client/IP approvals. No new role or
   role migration is needed.
3. Openprovider *reseller account*: dedicated API username/password. The
   user stores these in the authenticated dashboard. Password and upstream
   bearer are encrypted in private MySQL with AES-256-GCM and APP_KEY,
   never returned in public API responses or dashboard HTML.
4. Gateway *API client*: owned, initially disabled client with a one-time
   gateway-issued 256-bit bearer key, separately approved source IP(s) and
   active client/account checks.

## Onboarding flow

1. Admin enables SMTP settings at /admin/settings/smtp, after admin login
   and current password confirmation. SMTP settings are restricted to
   SMTPS port 465 or verified STARTTLS port 587. Password never redisplays.
2. With ACCOUNT_UI_ENABLED and ACCOUNT_REGISTRATION_ENABLED enabled,
   users POST email and a minimum 12-byte password to /account/register.
   IP/identity HMAC rate limits and a non-enumerating duplicate response apply.
3. Gateway sends a 30-minute one-time email token. GET /account/verify?token=...
   shows a confirmation form but does not verify automatically; a CSRF-
   protected POST consumes the token. Only users whose signup_source is
   self_service become active/approved; legacy manual registrations remain
   under administrator approval.
4. User logs in at /account/login. If ACCOUNT_INTEGRATION_ENABLED=true,
   user sets their own Openprovider account credentials (with gateway password re-confirmation) and creates disabled
   client and pending exact IPv4/IPv6 entries, with owner checks/audits.
5. Administrator must explicitly activate clients and approve requested
   source IPs with the separately enabled ADMIN_ACTIONS_ENABLED gate.
6. With ACCOUNT_KEYS_ENABLED=true, user issues one-time gateway bearer
   keys after password re-confirmation. Key is stored only as a digest
   and can be revoked.
7. With GATEWAY_PROXY_ENABLED=true, authorized requests to /v1/... are
   authenticated by the gateway bearer AND approved exact REMOTE_ADDR.
   The gateway uses a fixed HTTPS connection to api.openprovider.eu, caches
   the upstream bearer encrypted, and relays bounded JSON responses.

## Default-off feature flags

| Flag | Purpose |
|---|---|
| ADMIN_UI_ENABLED | Existing HTTPS admin authentication, without operator-IP gate |
| ADMIN_TWIG_UI_ENABLED | Existing Twig admin UI |
| ADMIN_ACTIONS_ENABLED | Admin user/client/IP approvals and changes |
| ADMIN_SMTP_SETTINGS_ENABLED | New SMTP configuration page |
| ACCOUNT_UI_ENABLED | Existing owner portal login |
| ACCOUNT_REGISTRATION_ENABLED | New registration, email verification and resend |
| ACCOUNT_INTEGRATION_ENABLED | Owner Openprovider/client/IP management |
| ACCOUNT_KEYS_ENABLED | Existing gateway bearer issuance/revocation |
| GATEWAY_PROXY_ENABLED | New public gateway API admission and relay |
| GATEWAY_PROXY_WRITE_ENABLED | Independent opt-in for costly/mutating methods |

APP_KEY must be 32 random bytes encoded as 64 hex digits and kept ONLY in
private .env; do not reuse LOGIN_RATE_LIMIT_KEY. ADMIN_UI_ALLOWED_IP has been
retired. Browser login is not source-IP-gated; **API client IP allowlisting
remains mandatory** and never trusts user-provided X-Forwarded-For.

## Safety and compatibility

- Fixed HTTPS upstream host, no redirects or user-specified URL.
- Requests max 16 KiB JSON, responses max 512 KiB JSON, bounded timeouts,
  strict method/path root allowlist and very restricted query parameters.
- No transparent /auth/login facade and no leaking Openprovider tokens.
- GATEWAY_PROXY_WRITE_ENABLED defaults false. Before enabling paid writes,
  verify official pinned OpenAPI spec, resource-specific ownership, scopes,
  replay/idempotency and side-effects. This first route set is not fully
  OpenAPI-compatible, regardless of the generic path syntax.
- Server outbound public IP may separately require Openprovider IP approval.
- All logs contain safe codes and request IDs only; no secrets, input body,
  email token URL, raw IP or credential fields.
- No background queue: mail sends synchronously. Retry UX exists but queue,
  credential rotation, MFA, password reset, CAPTCHA, SMTP bounce processing,
  per-client scopes and thorough paid-operation permission policy are later
  requirements before broad public launch.

## Database upgrade of a populated existing database

1. Take and verify backup, inspect selected DB and migration ledger.
2. Confirm migrations 001-003 completed. Do not re-import the full schema.
3. Apply the reviewed incremental SQL in
   database/sql/upgrade_202611100001.sql to a disposable local clone.
4. It adds users.signup_source DEFAULT manual and private smtp_settings
   and provider_connections. No existing user is auto-approved.
5. Validate version 004 checksum, schema readiness, admin login and records.
6. Only then consider manual phpMyAdmin production upgrade with backup.

**Warning:** The new migration is included in the global schema readiness
check. Deploying new source before adding migration 004 will return HTTP 503
on existing admin/account pages, even if new feature flags are false.
Coordinate migration and downtime; never auto-run DDL on cPanel.

## Required release tests

Run on trusted Mac with PHP 8.3:
- composer update phpmailer/phpmailer -W (review lock/vendor diff).
- composer install (including tests), PHP lint, PHPUnit, smoke.
- composer install --no-dev before committing any tracked private vendor
  files, and inspect staged diff to keep development deps out.
- frontend npm ci, lint, build; commit reviewed bundled assets if changed.
- Disposable local DB: register, SMTP delivery, verification POST, replay
  denial, login, owner connection, disabled client, pending IP, admin
  approvals, API key issue and revoke, allowed/denied transport IP, actual
  fixed upstream read calls using a dedicated test reseller.
- Adversarial: wrong host, forged XFF, wrong CSRF, wrong owner, duplicate
  email, signup throttling, malformed token, token expiry/replay, upstream
  failures, request size limits, redirected responses and secret redaction.
- Ensure production flags remain disabled until deliberate staged rollout.
