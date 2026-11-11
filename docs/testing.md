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

**Last developer-verified baseline before the simplification refactor:**
183 PHPUnit tests, 1057 assertions, 10/10 smoke tests; npm lint and
webpack build passed. These are historical results and do **not**
validate the new refactor. The new code requires a fresh test run.

The smoke script probes real gateway admission (missing bearer and
denied mutating route), health, IP helper and safe logging. It is not
a substitute for real DB, SMTP, Openprovider and browser integration.

## Isolated database and mail test

Use only the existing local `opgateway` development DB or a disposable
clone, never the production DB. Migration 004 must be completed first, then reviewed migration 005
applied once, then migration 006 applied once. Verify 6 completed ledger entries and 13
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

## Browser session invalidation on password reset

Admin and Account sessions created before this code change lack a password
fingerprint and must sign in again. New sessions hold only a fingerprint of
the stored hash verified at sign-in; each protected request compares against
the current eligible user's stored password hash. Changing that hash through
password recovery invalidates all previous browser sessions on their next
protected request. Stale CSRF data is also discarded. API bearer keys remain
independent and are not automatically revoked.

Test successful recovery in one browser while another logged-in browser has
an Account session and (separately) an Admin session. Confirm both old sessions
are redirected to login and freshly signed-in sessions work. No migration.

## Google OIDC browser flow — unreleased feature branch only

Google sign-in is NOT part of the `v0.1.0-alpha.4` production pilot. On the
`feat/google-oidc-password-fallback` branch, run PHP lint, PHPUnit and smoke
after a reviewed, backed-up manual migration 007 on the **local** database.
A fresh install needs 7 migration entries and 14 final tables. Never deploy
the OAuth source against production that is still at migration 006.

In Google Cloud Console create an OAuth 2.0 **Web application**, register the
redirect URI exactly as rendered by `/admin/settings/google` (production:
`https://opgateway.arsamnet.com/account/google/callback`), and enter its
client ID/secret only through the authenticated admin screen. No secrets
belong in .env, Git or chat logs. Google may reject custom `.test` domains:
use a registered HTTPS staging domain for live tests. Verify server outbound
HTTPS access to `oauth2.googleapis.com` and `www.googleapis.com` without
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
state, invalid nonce/aud/signature/expiration, network outages, revoked
local users, password reset mid-flow and unlink with wrong password.
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
