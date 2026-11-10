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

## UI and release review

Use real browsers at 320, 375, 390, 768, 1024 and 1440px. Verify
drawer, close/focus/Escape behavior, dark/light/system themes, form
validation, wrong password, CSRF, current admin role checks, API
allowlist and cross-owner denials. All authenticated HTML is
no-store and all bundled assets are same-origin.

Do not merge, deploy, or claim release readiness before
fresh automated and end-to-end results are reviewed.
