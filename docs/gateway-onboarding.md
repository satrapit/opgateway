# Onboarding, settings and gateway release checklist

> **Status (2026-11-26):** `v0.1.0-alpha.5` is a tagged development
> snapshot, not production approval. The only development branch is `main`.
> The established local site is `https://opgateway.test/`; no Docker
> setup is needed for this workflow. The local MySQL schema completed a
> read-only readiness test (1 PHPUnit test, 21 assertions) on commit
> `478e529`, with nine valid migration records and 15 tables.
> Real browser, SMTP, Google OAuth and rollback acceptance remain open.

## Configuration and security boundaries

The private `.env` contains only `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASSWORD`. Do not display or commit its contents.
All global settings, including SMTP and Google SSO, are stored in the
existing MySQL `settings` table. SMTP and Google passwords/secrets are
encrypted using the private `storage/app-secret.key`; never regenerate
that key, expose it in a test log or copy it into the public directory.

The shared `admin` role manages settings. Admin login is not restricted
by API-client IP allowlists. API operations require an active authorized
client and an exact approved source IP, with no trust in forwarded headers.
Paid/mutating Openprovider operations remain unavailable without a
reviewed operation policy.

## Existing local environment: opgateway.test

1. `git pull --ff-only origin main` after confirming the working tree is
   clean. Do not deploy these changes to production automatically.
2. The existing `opgateway.test` MySQL database has **nine** valid
   migrations and **15** tables based on the read-only test output on
   2026-11-26. Do **not** re-import the full schema, run all migrations,
   or apply an older upgrade SQL file to this database.
3. Recheck schema health without modifying it:

   ```sh
   OPGATEWAY_TEST_LOCAL_READONLY=1 \
     ./vendor/bin/phpunit tests-integration/LocalDatabaseReadOnlyTest.php
   ```

   If the active site's `vendor` lacks PHPUnit, use a dedicated QA
   worktree with `composer install` and point
   `OPGATEWAY_LOCAL_DB_ENV` at the existing checkout's private `.env`.
   Do not use `MigrationRunnerIntegrationTest.php` on a populated DB.
4. Verify `https://opgateway.test/health`, `/admin/login`, and
   `/account/login` over trusted local HTTPS.
5. For a real email test, sign in as an existing authorized administrator.
   In `/admin/settings/smtp`, inspect configuration without disclosing
   passwords. Keep **Gmail inbox verification** disabled while testing the
   normal link. Use a dedicated, owned test inbox and unique test identities;
   every registration, token issue, reset and login attempt can create
   permanent database/audit rows or affect rate limits.
6. In a clean browser profile, visit `/account/register`. Register a
   disposable mailbox, inspect the received message, confirm using its
   **ordinary** `/account/verify?token=...` link, then verify that reusing
   that link does not verify a second time. Do not copy token-bearing URLs
   into logs, public screenshots or chats.
7. Sign in through `/account/login`, complete the first/last name profile
   when prompted, log out, and verify the logged-out session cannot access
   protected account actions.
8. Test password recovery in a separate disposable account. Request
   `/account/forgot-password`, follow the email's reset link, set a new
   password, verify the old password is rejected and the new one works.
   Check that an already-used reset link cannot be reused. Also verify
   that another prior session is invalidated.
9. For a Gmail inbox action test, first inspect
   [Gmail inbox verification](gmail-inbox-verification.md) and sender
   registration requirements. Do not mistake an ordinary email-link test
   for proof that Gmail renders its inline action button. The action
   setting remains disabled by default and should not be enabled in
   production without separate approval and acceptance.
10. Continue owner Openprovider credentials, disabled-client creation,
    IP approval, key issuance and gateway denial tests only with
    dedicated local test accounts and no live/paid upstream changes.

## Production deployment boundary

The only production hostname is `https://opgateway.arsamnet.com`.
Before considering deployment, inspect the **actual production** migration
ledger, separately back up its MySQL database and application encryption
key, rehearse an exact-schema-compatible rollback and confirm browser,
SMTP and Google provider acceptance. The tagged pre-Google
`v0.1.0-alpha.4.1` baseline is **not** a universal rollback target
after later migrations. Never auto-run migrations from Git/cPanel;
deployment remains a reviewed manual operation. Keep `main` for ongoing
development and use semantic version tags for releases.
