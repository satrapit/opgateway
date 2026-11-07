# Doctrine DBAL and MySQLi controlled adoption

## Current status

The production OPGateway HTTP entry still uses the existing PDO-based
setup and admin repositories. It must NOT automatically depend on the
Doctrine package because this deployment has no Composer CLI and only
copies public/index.php using cPanel Git Version Control.

New opt-in source adapters:
- `DoctrineConnectionFactory` accepts only `pdo_mysql` or `mysqli`;
  forces the connection to open and validates all connection fields.
- `DbalLoginAccountRepository` implements LoginAccountRepository.
- `DbalUserRepository` implements UserRepository.
- `DbalAdministratorAuthorization` revalidates privileged membership.\n  Both implementations share `AdministratorAuthorization`, keeping HTTP\n  controllers independent from the selected database adapter.
- `MysqliConnectionProbe` provides a safe native mysqli diagnostic that
  does not need Composer and is inactive by default.

All DBAL queries bind values explicitly. Dynamic table names, dynamic SQL,
raw request SQL, automatic migrations and arbitrary driver names are denied.

## Diagnosis of the current PDO 503

Private .env options (defaults):

```dotenv
DB_DRIVER=pdo_mysql
DB_UNIX_SOCKET=
DB_DIAGNOSTIC_MYSQLI_ENABLED=false
```

When and only when `SETUP_ENABLED=true`, the source IP is allowlisted,
HTTPS is verified, and PDO connection fails, an operator can temporarily
set `DB_DIAGNOSTIC_MYSQLI_ENABLED=true`. One GET to
`/setup/administrator` then emits the usual PDO failure and a second
metadata-only event `setup.mysqli_connection_probe`. The second event
contains only `context.probe_result`: e.g. `connected`,
`authentication_rejected`, `connection_failure`, or
`driver_unavailable`. It never logs a password, DSN or driver error
string and never mutates SQL or grants access.

After the single test, set `DB_DIAGNOSTIC_MYSQLI_ENABLED=false` and
`SETUP_ENABLED=false`. Do not send the contents of .env to support.

If mysqli succeeds and PDO fails, compare transport differences:
`localhost` may resolve to a Unix socket instead of TCP. Do not
automatically switch socket or grant privileges; review cPanel MySQL
host settings and the PHP runtime first. Schema reconstruction does
not fix a connection failure.

## Complete Doctrine rollout — remaining prerequisite

The DBAL 4.x dependency and its transitive packages must be built in a
PHP 8.3-capable build environment using Composer. Commit the consistent
composer.json and composer.lock together after a controlled update and
dependency audit. Package the vendor folder including Composer's
autoload.php in a private release archive that can be uploaded and
extracted in cPanel File Manager OUTSIDE Document Root. Do not place
vendor binaries, auth credentials or download tokens in Git.

Because this build environment cannot currently resolve Packagist or
regenerate a verified composer.lock, this stage deliberately does not
change composer.json/lock or enable Doctrine classes in production.
Do not set DB_DRIVER=mysqli expecting existing PDO services to switch:
it is consumed by the future DoctrineConnectionFactory only.

Next, migrate the remaining rate limiter, audit, schema verification and
transactional administrator installer, then use the DBAL factories
exclusively after a real disposable MySQL test and rollback review.
Public /v1 remains disabled; no database schema change or GitHub
workflow has been introduced.

Official DBAL documentation:
https://www.doctrine-project.org/projects/doctrine-dbal/en/4.6/reference/configuration.html


## Interpreting both connection failures

The mysqli probe now reads `mysqli::connect_errno` from its own failed
connection, with a global errno fallback, and classifies a numeric
`mysqli_sql_exception::getCode()` if an exception is thrown. The full
exception message and host/user/database names are never logged.

A `probe_result=unclassified` is not proof of a malformed or corrupted
schema. It can mean the driver supplied no recognized native errno.
Ensure the new code is deployed, run one controlled GET from the
allowlisted operator IP, then switch both diagnostic and setup flags off.
If it persists, check MySQL account grants and the local socket/host
settings independently; do not rebuild the schema based on this event.
