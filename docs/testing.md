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

The last user-verified baseline before administrator client/IP onboarding was **93 PHPUnit tests / 343 assertions** and **10/10 smoke checks**. A new baseline must be recorded only after running the expanded suite; do not infer test success from code review.

Smoke tests confirm `/health`, deny-by-default `/v1`, IP helper behavior and safe metadata; they **do not** prove live authentication, schema compatibility, ownership enforcement or upstream connectivity. Test administrator login/logout over HTTPS in both local and hosting environments separately.

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
