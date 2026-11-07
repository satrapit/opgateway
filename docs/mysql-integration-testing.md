# Disposable MySQL integration tests

Use an isolated MySQL 8.0 Docker container on the Mac. **Never run the
integration test against cPanel or production.** Its database schema is
hardcoded to `opgateway_integration_test` on `127.0.0.1:13306` and the
test refuses to start if that database already contains tables. Tests modify
the database.

## Start a new container

Run the following commands in the **same Mac terminal session**:

```bash
cd ~/Sites/opgateway
docker info >/dev/null
export OPGATEWAY_TEST_MYSQL_PASSWORD="$(openssl rand -hex 24)"
docker run -d --name opgateway-mysql-it \
  -p 127.0.0.1:13306:3306 \
  -e MYSQL_RANDOM_ROOT_PASSWORD=yes \
  -e MYSQL_DATABASE=opgateway_integration_test \
  -e MYSQL_USER=opgateway_test \
  -e MYSQL_PASSWORD="$OPGATEWAY_TEST_MYSQL_PASSWORD" \
  mysql:8.0

# MySQL may take some time to finish its first initialization.
until docker exec opgateway-mysql-it mysqladmin ping \
  -h 127.0.0.1 -u opgateway_test \
  "-p$OPGATEWAY_TEST_MYSQL_PASSWORD" --silent >/dev/null 2>&1; do sleep 2; done

./vendor/bin/phpunit tests-integration/MigrationRunnerIntegrationTest.php
```

Do not enable shell tracing with `set -x`. The generated password is
ephemeral, not committed or written to any file. The Docker container
environment inherently holds the test password until removal.

Once the test passes, remove the disposable container and clear the variable:

```bash
docker rm -f opgateway-mysql-it
unset OPGATEWAY_TEST_MYSQL_PASSWORD
```

If initialization fails, inspect `docker logs opgateway-mysql-it`
without sharing secrets. The test is intentionally **not** in the default
`composer test` suite.

## Validated behaviors

The test verifies DDL application, ledger completion, idempotent rerun
and advisory lock contention with a second connection. Additional fault
injection tests (interrupted migration, changed checksums, retry protection)
are required before authorizing a production migration.

For all tests, keep `/v1` routes disabled. No installer endpoint,
automatic deploy migration or production database connection is introduced.
