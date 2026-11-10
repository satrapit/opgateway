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
clone, never the production DB. Migration 004 must be completed once
before exercising new code. Verify 4 completed ledger entries and 12
tables. Test admin SMTP UI, verified email signup, expired/replayed
links, owner account, disabled client/IP, approvals, one-time key issue
and revoke, denied wrong source IP and actual safe upstream read. Verify
metadata logs do not contain secrets.

`tests-integration/MigrationRunnerIntegrationTest.php` is separate
from the standard suite because it performs database DDL. It is
suitable only for an explicitly disposable MySQL instance, never a
working or production database. No automated migration on cPanel.

## UI and release review

Use real browsers at 320, 375, 390, 768, 1024 and 1440px. Verify
drawer, close/focus/Escape behavior, dark/light/system themes, form
validation, wrong password, CSRF, current admin role checks, API
allowlist and cross-owner denials. All authenticated HTML is
no-store and all bundled assets are same-origin.

Do not merge, deploy, or claim release readiness before
fresh automated and end-to-end results are reviewed.
