# Single Medoo/PDO connection architecture

OPGateway uses `catfan/medoo` as its only database connection layer.
Medoo itself requires PDO; it is **not a MySQLi driver**. WordPress and
WHMCS can use either PHP MySQL transport, but Medoo specifically uses
`pdo_mysql`. A server-side error 1524 will not be fixed merely by
changing PHP database libraries.

`MedooConnectionFactory::open()` creates and validates a Medoo
connection from the private .env. Existing transactional code calls
`MedooConnectionFactory::pdo()`, which returns that Medoo instance's
underlying PDO handle. No second connection is constructed.

`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` and
optional `DB_UNIX_SOCKET` remain the only connection settings.
If DB_UNIX_SOCKET is set, it must be an absolute path and replaces host
and port transport options. Do not set it speculatively.

## Mandatory dependency upgrade before deploy

On a local machine with Composer:

```bash
composer remove doctrine/dbal --no-update
composer require catfan/medoo:^2.6 --no-update
composer update --with-all-dependencies
composer validate --strict --no-check-publish
php scripts/lint.php
composer test
```

The first two commands are only for **starting from an older checkout**
that still requires Doctrine. If `composer.json` already lists only
Medoo, run `composer update --with-all-dependencies` directly.

Review the Composer lock and vendor diff. Deploy the matching
`composer.lock` and `vendor` together into the private checkout
*before* deploying the new PHP entrypoint. Never copy dependencies into
Document Root; never store credentials or environment files in Git.

The SQL schema, migration ledger and private .env must not be deleted,
recreated or reimported during this change.
