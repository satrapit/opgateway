# Onboarding, settings and gateway release checklist

> This is an **unreleased** change in draft PR #3. It must not be deployed
> to production before local end-to-end and security tests.

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

- SMTP settings live in MySQL `smtp_settings`, edited at
  `/admin/settings/smtp` with current-password confirmation and CSRF.
- Owner-specific Openprovider reseller credentials live encrypted in
  `provider_connections` and are updated in the account portal.
- The HTTPS public host comes from the trusted web server's
  `SERVER_NAME`. It must match the request's Host exactly.
- The app refuses unknown .env keys, including retired flags; delete old
  entries from local .env after pulling this branch.
- The AES-256-GCM encryption key is generated once in private
  `storage/app-secret.key` (0600 permissions). HMAC throttling derives
  a distinct key from it via HKDF. It is **not in MySQL or .env**.
  Back up the key securely and do not regenerate it: losing it makes
  existing encrypted passwords/tokens unrecoverable.
- Gateway writes with financial/ownership consequences are **not
  released**; no switch exists to bypass their policy review.

## Local onboarding flow

1. On an independent local alpha.3 DB, verify and back up migration
   history 001–003. Apply the reviewed additive
   `database/sql/upgrade_202611100001.sql` (004) exactly once.
   It adds `users.signup_source`, `smtp_settings` and
   `provider_connections`; old users remain `manual`.
2. Verify four `completed` migrations and 12 tables. **Never import
   the full base schema over an existing populated DB.**
3. Use HTTPS with a correctly configured canonical vhost. Sign in as
   `admin`; open `/admin/settings/smtp` and save actual test SMTP.
4. Register a new account, receive mail, submit a one-time verification
   token, verify replay/expiration denial, and sign in.
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

## Production deployment blocker

The current production alpha.3 schema lacks migration 004. New code
checks the complete migration ledger, so deploying before a manual,
backed-up and verified schema upgrade would cause authenticated
/admin and /account pages to return HTTP 503. The rollout needs a
separate maintenance window and validation. Never run migrations
automatically on cPanel. Keep this PR in Draft until finished.
