# Testing OPGateway

## Regular local validation

From a dedicated, disposable Git worktree of the reviewed `main` commit on a trusted Mac:

```bash
composer install --prefer-dist
composer validate --strict --no-check-publish
php scripts/lint.php
./vendor/bin/phpunit --display-phpunit-notices
php scripts/smoke.php
cd frontend
npm ci
npm run lint
npm test
npm run build
cd ..
git diff --check
git diff --exit-code -- public/assets/admin
git status --short
```

The current `main` includes nullable-password Google-first onboarding,
provider-authoritative versus external-domain collision handling, revocation,
action-step-up, Account Security and optional Gmail inbox actions (disabled
by default). Record test counts only for the exact commit actually tested.

For the prepared Migration 008 code-only rollback, run
`tests/Migration008RollbackCompatibilityTest.php` together with the Google
browser-flow, session-revocation and password-reset policy tests before the
full suite. The exact rollback snapshot must deny an unknown Google subject
without creating or joining a user while continuing subject login for an
already linked Google-only account.

Local QA on 2026-11-26 for commit `86171ad` (the code snapshot in
`v0.1.0-alpha.5`): **282 PHPUnit tests / 2,236 assertions**,
**10/10 smoke checks**, **12/12 Node frontend tests**, PHP lint,
strict Composer validation, frontend lint, npm audit and production
Webpack build passed. The next changes in `main` update the separate
real-MySQL integration test for migration 009 and SMTP/email token behavior;
these post-tag changes require their own execution.
No restored-backup rehearsal, real Gmail/SMTP acceptance, full browser audit,
or fresh disposable-MySQL integration result is claimed.

The smoke script probes real gateway admission (missing bearer and
denied mutating route), health, IP helper and safe logging. It is not
a substitute for real DB, SMTP, Openprovider and browser integration.

## Existing opgateway.test database: read-only acceptance

**Verified on 2026-11-26 against the existing local MySQL database:** the
isolated worktree at `478e529` ran `LocalDatabaseReadOnlyTest` successfully:
**1 test, 21 assertions**, PHP 8.3.30 / PHPUnit 12.5.43. The
`/health`, `/account/login` and `/admin/login` endpoints each returned
HTTP 200 on `https://opgateway.test/`. This confirms schema/read-only
readiness, **not** email delivery, authenticated browser flows, or SMTP
acceptance. The separate DDL-running MySQL integration test has not been
executed in this environment.


The developer already has an HTTPS `https://opgateway.test/` installation
with a populated local MySQL database. **Do not** run
`MigrationRunnerIntegrationTest.php` against that database: it performs DDL,
inserts test users and requires a completely empty schema.

To validate the existing local data **without changing any rows or tables**,
from the local repository checkout with its current .env file run:

```sh
OPGATEWAY_TEST_LOCAL_READONLY=1 \
  ./vendor/bin/phpunit tests-integration/LocalDatabaseReadOnlyTest.php \
  --display-phpunit-notices
```

When PHP dependencies are installed in a separate QA worktree and the site's
ignored `.env` is only present in the main local checkout, use:

```sh
OPGATEWAY_TEST_LOCAL_READONLY=1 \
OPGATEWAY_LOCAL_DB_ENV="$HOME/Sites/opgateway/.env" \
  ./vendor/bin/phpunit tests-integration/LocalDatabaseReadOnlyTest.php \
  --display-phpunit-notices
```

The test rejects non-loopback database hosts and starts a MySQL read-only
transaction. It inspects the migration ledger and checksums, 15 tables,
required identity/profile columns and the optional Gmail setting. It never
reads SMTP passwords or generates verification tokens. A missing migration
is a signal to review backups and migration plans **manually**, not to run
an automatic repair. The local HTTPS site's browser/login flows still require
separate acceptance testing.

## Isolated database and mail test

Use only an explicitly disposable empty database or restored backup clone,
never an existing working or production database. The current migration
catalog contains **nine** entries (001–009) and yields **15** tables.
`tests-integration/MigrationRunnerIntegrationTest.php` runs the first
eight migrations, seeds an existing Google-linked account, applies 009,
checks idempotency and data preservation, verifies the Gmail SMTP setting,
and exercises actual one-use token consumption and manual-invite approvals.
The test also checks MySQL advisory migration locks.

`tests-integration/MigrationRunnerIntegrationTest.php` is separate
from the standard suite because it performs database DDL. It is suitable only for an explicitly disposable **empty MySQL 8** database
named `opgateway_integration_test` at `127.0.0.1:13306` using
`opgateway_test` and the ephemeral
`OPGATEWAY_TEST_MYSQL_PASSWORD` environment variable. The test performs
DDL and requires a completely empty schema. No automatic migration on cPanel.
Never point a port-forward or alternate server at a production database.

## Shared Account/Admin UI test gate

The owner portal now uses the same Twig/TailAdmin CSS and CSP-safe Alpine
assets as the admin portal. First rebuild the local assets with
`cd frontend && npm ci && npm run lint && npm test && npm run build`, then rerun PHP
lint and PHPUnit, including `AccountDesignSystemTest`. Test account
login/registration/verification, dark/light/system controls, mobile
drawer at 320/375/390px, desktop at 1024/1440px, provider/account forms,
and the one-time API key page. Test forgot-password email, expired and reused
links (including reopening a used URL), confirmation mismatch, and sign-in
with the new password. Confirm submit buttons disable immediately on native
submission in both portals, the initiating button shows a spinner (or
"Please wait…" for input submits), repeated clicks are ignored, canceled
review dialogs/invalid HTML form input do not show progress or lock buttons,
and Back navigation restores the original label and state. Check icon-only
logout buttons and reduced-motion mode. Run `npm test` for submission tests. Validate actual browser asset responses
and strict `script-src 'self'` / `style-src 'self'` CSP with no inline CSS.

## Browser session invalidation on authentication-method change

Admin and Account sessions created before this code change lack the current
authentication-session marker and must sign in again. New sessions hold only
an opaque marker derived from `auth_session_version`; each protected request
compares it with the current eligible user. Password set/change/reset and
Google unlink increment the version, invalidating all previous browser sessions
and their CSRF data. API bearer keys remain independent.

Test successful recovery in one browser while another logged-in browser has
an Account session and (separately) an Admin session. Confirm both old sessions
are redirected to login and freshly signed-in sessions work. No migration.

## Google-first OIDC browser flow — manual production acceptance pending

Google-first sign-in is merged into `main`, but a successful merge does
not mean it has been deployed or accepted against the real provider.
Use a disposable local backup copy at **migration 009** for browser
acceptance and never run schema changes against production from a test.

For the sole deployed hostname `opgateway.arsamnet.com`, first verify and
deploy the compatibility-only pre-Google release (planned alpha.4.1 from
PR #5) while production still has six migrations. It accepts migration 007
without activating Google. Back up the production DB and private secret,
run migration 007 manually during maintenance, and only then deploy
reviewed Google OAuth code. Code-only rollback after 007 must use
alpha.4.1, not alpha.4.

In Google Cloud Console create an OAuth 2.0 **Web application**, register the
redirect URI exactly as rendered by `/admin/settings/google` (production:
`https://opgateway.arsamnet.com/account/google/callback`), and enter its
client ID/secret only through the authenticated admin screen. No secrets
belong in .env, Git or chat logs. Google may reject custom `.test` domains:
use the registered production HTTPS domain for the final consent flow.
No separate staging domain exists. Verify server outbound HTTPS access to `oauth2.googleapis.com` and `www.googleapis.com` without
disabling TLS validation.

Browser acceptance must verify the OAuth callback receives the PHP session
cookie after Google's top-level GET redirect. It uses explicit
`SameSite=Lax; Secure; HttpOnly`, not Strict or None; session-bound state,
nonce and all POST CSRF checks remain mandatory.

Browser acceptance: register with local password, verify email and sign in
without admin approval; confirm password to connect Google;
consent with the Google account using the same verified email; sign out and
sign back in using Google, then verify local password still works. Test
unlinked accounts, email mismatch, duplicate subjects, expired/replayed
state, invalid nonce/aud/signature/expiration, missing/stale step-up `auth_time`,
network outages, revoked local users, password reset mid-flow and unlink with
wrong password.
Confirm that the link POST first returns to `/account/google/continue`, the
strict CSP still contains `form-action 'self'`, automatic navigation reaches
Google in Chromium, and the visible Continue to Google link works with
JavaScript disabled and by keyboard. Replay or direct access to the
continuation route must fail before creating a new OAuth flow. For an existing
unlinked mailbox, test missing/corrupt SMTP settings, token-storage failure and
transport failure separately; each must return a generic response, cancel any
issued token when possible and write only its dedicated metadata event.
No automatic account linking by email. Inspect no-store, CSP, session
cookies and no-referrer for the Google callback.

Also test a first-time Google registration (immediate `/account` session), a
returning subject, an eligible exact-email Gmail collision, a signed Workspace
`hd` claim matching the email domain, and an external-domain collision that
still requires mailbox proof. Verify mismatched/malformed `hd`, conflicting
subjects, disabled users and manual-pending users cannot be linked or activated.
For password signup, confirm the verification-link GET itself does not consume
the token, the local script submits the CSRF-protected POST once, the success
page says Email verified and links to sign-in, and no session is created.
Repeat with JavaScript disabled using the visible Verify email button. Test a
GET-only prefetch client and a JavaScript-capable scanner; document that only a
deliberate second action can fully distinguish the latter from a user.

Unit tests cannot substitute for real Google, MySQL and browser integration.
Do not deploy or enable the relevant production features before these
provider/browser acceptance checks pass.

## UI and release review

Use real browsers at 320, 375, 390, 768, 1024 and 1440px. Verify
drawer, close/focus/Escape behavior, dark/light/system themes, form
validation, wrong password, CSRF, current admin role checks, API
allowlist and cross-owner denials. All authenticated HTML is
no-store and all bundled assets are same-origin.

Do not merge, deploy, or claim release readiness before
fresh automated and end-to-end results are reviewed.


## Independent login settings / CAPTCHA regression gate

After checkout of `feat/admin-settings-users-image-captcha` on Mac:
run `php scripts/lint.php`, `./vendor/bin/phpunit
tests/LoginSettingsPolicyTest.php --display-phpunit-notices`, full PHPUnit,
smoke, `npm ci && npm run lint && npm test && npm run build`.
Verify the Settings parent has a bundled gear icon and rotating chevron,
and Security, Google SSO and Email each open a real separate page.
There must be no All settings or intermediate Login overview page.
Verify keyboard, focus order, current-page links and mobile drawer behavior.
Verify CSRF and invalid/expired administrator session errors cannot
modify settings; routine actions no longer ask for the admin password.

Test Account and Admin password login with raster offline CAPTCHA: correct
binary PNG, no plaintext response in HTML/session, wrong answer,
expired challenge, wrong session/role, replay, invalid CSRF, rate limit
and a real successful password login. Then configure the v2 checkbox
site and secret keys through the Security page. On HTTPS
check the CSP allows the Google script/frame, the backend rejects
missing/replayed/expired tokens and wrong hostname, and that provider
failure fails closed. Ensure the Google OAuth Client credentials are
separate, disabling SSO leaves local password login working, and Google
account linking uses the verified same email with a current-password
check. Review recovery instructions before selecting reCAPTCHA on
the single production hostname.

## Administrator creation screens

Run `./vendor/bin/phpunit tests/AdminUserInvitationTest.php
tests/AdminCreationPagesTest.php --display-phpunit-notices`.
Review invitations on `/admin/users/invite`: account duplicate suppression,
email resend for an unverified invitation, enforced role/CSRF/current admin
password, SMTP unavailable, metadata-only audit, and the workflow from
email verification to manual approval to own-password setup.
Check `/admin/clients/create` creates a disabled client for an approved owner,
and `/admin/ips/request` only accepts exact IPs of active clients, with
separate activation/approval in the existing administration pages.
