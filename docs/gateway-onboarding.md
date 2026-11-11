# Onboarding, settings and gateway release checklist

> **Current main:** pre-Google `v0.1.0-alpha.4.1`. Google sign-in
> remains under development in Draft PR #9; it requires migration 007
> and separate reviewed tests and release planning.

## Configuration: minimal by design

The private `.env` contains only:
```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=opgateway
DB_USER=opgateway
DB_PASSWORD=
```

There are **no application feature flags**. All real routes use one code
path. The single privileged role is `admin`; no superadmin.

- SMTP settings are `smtp.*` key/value entries in shared MySQL `settings`
  (encrypted password), edited at
  `/admin/settings/smtp` with current-password confirmation and CSRF.
- Owner-specific Openprovider reseller credentials live encrypted in
  `provider_connections` and are updated in the account portal.
- The HTTPS public host comes from the trusted web server's
  `SERVER_NAME`. It must match the request's Host exactly.
- The app rejects unknown .env keys, including retired flags. Review
  the private production .env manually, keeping only five MySQL keys
  (see [configuration](configuration.md)); never expose secret values.
- The AES-256-GCM encryption key is generated once in private
  `storage/app-secret.key` (0600 permissions). HMAC throttling derives
  a distinct key from it via HKDF. It is **not in MySQL or .env**.
  Back up the key securely and do not regenerate it: losing it makes
  existing encrypted passwords/tokens unrecoverable.
- Gateway writes with financial/ownership consequences are **not
  released**; no switch exists to bypass their policy review.

## Local onboarding flow

1. The existing local development database has completed migrations 001–005.
   Back it up and apply **only** `database/sql/upgrade_202611100003.sql`
   (006) once. Verify **six** completed migrations and **13** final tables.
   Do not re-run previous upgrades or import the full schema into existing data.
2. A fresh database created using the one-time full-schema artifact includes
   all six migrations. A database still on alpha.3 must instead receive
   the reviewed incremental upgrades 004, then 005, then 006 after backup.
   Migration 005 moves encrypted SMTP data to `settings`; migration 006
   adds `password_reset_tokens` without changing existing users.
3. Use HTTPS with a correctly configured canonical vhost. Sign in as
   `admin`; open `/admin/settings/smtp` and save actual test SMTP.
4. Register a new account, receive mail, submit a one-time verification
   token, verify replay/expiration denial, and sign in. Test forgot-password
   request, email delivery, invalid/expired/replayed reset tokens, password
   confirmation and signing in with the new password. Review existing session
   invalidation before considering password recovery production-ready.
5. Set the owner's Openprovider credentials, create a disabled client
   and request an IP. Admin activates the client and approves the IP.
6. Issue an API key with a password recheck and call an allowed GET or
   non-mutating availability-check operation from that source IP.
7. Verify denials for unknown keys, wrong IP, disabled client, revoked
   key, forged forwarding headers, CSRF errors, cross-owner changes and
   upstream failures. Confirm no secrets enter logs/HTML.
8. Review actual Openprovider API contract and operation-specific safety
   before introducing any mutating/paid routes.

## Security boundaries

Browser admin access is **not** source-IP restricted. API clients **are**
restricted to exact approved transport addresses from `REMOTE_ADDR`.
Passwords are hashed when used for gateway login, and encrypted only
when Openprovider/SMTP need later decryption. Public request bodies
and upstream tokens are never logged. Responses are bounded and outbound
traffic targets only the fixed Openprovider HTTPS API host.

The absence of a feature flag does not mean everything is safe to expose:
roles, ownership, CSRF, rate limits and explicitly supported operation
policies remain mandatory.

## Database readiness and Google release

The pre-Google `v0.1.0-alpha.4.1` requires six completed migration rows and
accepts the exact completed Google migration 007 for code-only rollback.
Google OAuth is not included in this release and is still in Draft PR #9.
Keep the production database unchanged until the review, backup and
single-domain rollout plan is completed. Never run migrations automatically
on cPanel.

The Google-first optional-password work adds manual migration 008 after 007.
It must be rehearsed only on a disposable restored backup and reviewed with
[its non-destructive rollback plan](google-first-password-rollback.md). This
branch expects eight completed migrations and fifteen final tables; production
must remain at seven until the new code and rollback build are approved.
