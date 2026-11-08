# Testing and validation

Run locally on PHP 8.3:

```bash
composer install
php scripts/lint.php
composer test
./vendor/bin/phpunit --display-phpunit-notices
php scripts/smoke.php
composer validate --strict --no-check-publish
```

The latest user-verified baseline before the Twig/TailAdmin admin UI refactor was **131 PHPUnit tests / 609 assertions, zero notices**, and **10/10 smoke checks**. A new baseline must be recorded only after running the expanded suite; do not infer test success from code review.

Smoke tests confirm `/health`, deny-by-default `/v1`, IP helper behavior and safe metadata; they **do not** prove live authentication, schema compatibility, ownership enforcement or upstream connectivity. Test administrator login/logout and the separately disabled owner account/credential routes over HTTPS in a real browser; unit tests do not substitute for real MySQL authorization or key handling.

## Optional disposable MySQL integration test

`tests-integration/MigrationRunnerIntegrationTest.php` is **excluded** from the normal PHPUnit suite because it modifies the target database. It uses the fixed empty schema `opgateway_integration_test` on `127.0.0.1:13306`. **Never point it at the local working database or the production DB.**

On a Mac with Docker, create an isolated MySQL 8.0 instance:

```bash
export OPGATEWAY_TEST_MYSQL_PASSWORD="$(openssl rand -hex 24)"
docker run -d --name opgateway-mysql-it \
  -p 127.0.0.1:13306:3306 \
  -e MYSQL_RANDOM_ROOT_PASSWORD=yes \
  -e MYSQL_DATABASE=opgateway_integration_test \
  -e MYSQL_USER=opgateway_test \
  -e MYSQL_PASSWORD="$OPGATEWAY_TEST_MYSQL_PASSWORD" mysql:8.0

# After the test MySQL server is accepting connections:
./vendor/bin/phpunit tests-integration/MigrationRunnerIntegrationTest.php

docker rm -f opgateway-mysql-it
unset OPGATEWAY_TEST_MYSQL_PASSWORD
```

Keep all test credentials disposable; never run `set -x` when passing secrets. No GitHub workflow is used for tests.

## Twig/TailAdmin test gates

`AdminViewMigrationTest` tests the existing adapter's role/session boundary with an injected renderer, even when Twig is not installed. `TwigAdminPageRendererTest` checks a fail-closed missing-dependency path or, once Twig is installed, real template rendering and escaping. This is not a substitute for a real Twig test run. After the local lock/build step, use `npm run lint && npm run build` in `frontend/`, PHP lint/PHPUnit/smoke tests at repository root, and manually test 320/375/390/768/1024/1440px, dark/light/system, Keyboard Tab/Escape, denied IP/role/CSRF, CSP console warnings and zero HTTP requests to external CDNs. Do not report these as passing until completed.

## Integrated UI branch — rebuild required

Commit `e5fcb72` on the **previous parallel** branch passed 136 PHPUnit tests, 640 assertions, 10/10 smoke checks and Webpack build. These results do not validate this consolidated renderer and frontend.

Run locally:

```bash
composer update twig/twig -W
composer validate --strict --no-check-publish
php scripts/lint.php
./vendor/bin/phpunit --display-phpunit-notices
php scripts/smoke.php
cd frontend
npm install
npm run lint
npm run build
test -s ../public/assets/admin/app.css
test -s ../public/assets/admin/app.js
test -s ../public/assets/admin/theme-init.js
```

Commit fresh `composer.lock`, `frontend/package-lock.json` and three matching `/public/assets/admin/` files. Then verify local HTTPS/IP/CSRF/role boundaries, real login and read-only dashboard, both mobile and desktop widths (320/375/390/768/1024/1440), keyboard focus/drawer behavior, dark/light/system preferences including login, CSP and owner portal regression.
