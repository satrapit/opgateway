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

The last user-verified baseline before owner credential portal work was **105 PHPUnit tests / 439 assertions, zero notices**, and **10/10 smoke checks**. A new baseline must be recorded only after running the expanded suite; do not infer test success from code review.

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

## Admin UI test gate (not yet verified)

After installing Twig and building assets on the local Mac:

```bash
composer update twig/twig --with-dependencies
composer validate --strict --no-check-publish
php scripts/lint.php
./vendor/bin/phpunit --display-phpunit-notices
php scripts/smoke.php
cd frontend
npm install
npm run check
npm run build
test -s ../public/assets/admin.css
test -s ../public/assets/admin.js
test -s ../public/assets/theme-init.js
```

Commit verified `composer.lock` and `frontend/package-lock.json`, then use `npm ci` for subsequent reproducible builds. Perform hands-on browser checks at 320/375/390/768/1024/1440px, light/dark/system (including refresh), keyboard focus on mobile drawer, destructive confirmation, disabled review UI, local CSP enforcement and real permission/CSRF failure paths. Verify the Account Portal remains unchanged and default-off provider routes still return 503. Do not claim success until checks actually ran.
