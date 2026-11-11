# Testing OPGateway

## Regular local validation

From the feature branch on the developer's Mac:

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
composer install --no-dev --prefer-dist --optimize-autoloader
git status --short
```

The Google-first branch adds nullable-password, onboarding, collision,
revocation, action-step-up and Account Security coverage. Do not copy a prior
test count into release notes: record the fresh PHPUnit, 10 smoke checks and
10 frontend tests from the exact reviewed commit.

Local automated result on 2026-11-12 for this working tree: 252 PHPUnit tests
with 1997 assertions, 10/10 smoke checks and 10/10 Node frontend tests; Composer
validation, PHP lint, npm lint, production Webpack build and asset allowlist
inspection completed successfully. The separate migration runner also passed
on a disposable MySQL 8.0.46 container (1 test, 31 assertions), including the
first seven migrations, seeded pre-008 account/hash/identity preservation,
migration 008, idempotency, the advisory lock and exact schema postconditions.
This is not a restored-backup rehearsal, real-browser,
SMTP or Google-provider acceptance result; those release gates remain open.

The smoke script probes real gateway admission (missing bearer and
denied mutating route), health, IP helper and safe logging. It is not
a substitute for real DB, SMTP, Openprovider and browser integration.

## Isolated database and mail test

Use only an explicitly disposable empty database or restored backup clone,
never a working or production database. On this branch, apply migrations 004
through 008 in catalog order and verify 8 completed ledger entries and 15 final
tables. Test admin SMTP UI, verified email signup, expired/replayed
links, owner account, disabled client/IP, approvals, one-time key issue
and revoke, denied wrong source IP and actual safe upstream read. Verify
metadata logs do not contain secrets.

`tests-integration/MigrationRunnerIntegrationTest.php` is separate
from the standard suite because it performs database DDL. It is
suitable only for an explicitly disposable MySQL instance, never a
working or production database. No automated migration on cPanel.

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

## Google-first OIDC browser flow — unreleased feature branch only

Google sign-in is NOT part of the production pilot. On this branch, run PHP
lint, PHPUnit and smoke after reviewed migrations 007 and 008 on a disposable
**local backup copy**. A fresh install needs 8 migration entries and 15 final
tables. Never deploy this source against production or run its migrations there.

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

Browser acceptance: register with local password, verify email and receive
admin approval; sign in with password; confirm password to connect Google;
consent with the Google account using the same verified email; sign out and
sign back in using Google, then verify local password still works. Test
unlinked accounts, email mismatch, duplicate subjects, expired/replayed
state, invalid nonce/aud/signature/expiration, missing/stale step-up `auth_time`,
network outages, revoked local users, password reset mid-flow and unlink with
wrong password.
No automatic account linking by email. Inspect no-store, CSP, session
cookies and no-referrer for the Google callback.

Unit tests cannot substitute for real Google, MySQL and browser integration.
Do not merge/deploy the feature before these checks pass.

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
