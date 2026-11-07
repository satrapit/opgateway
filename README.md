# OPGateway

Security-first PHP 8.3 Openprovider API Gateway — early foundation only.

> **Not production-ready.** No authenticated client API, tenant isolation, or upstream
> transport exists. All `/v1` routes continue to return HTTP 503.

## Shared-cPanel hosting requirements

This project targets standard shared hosting with **cPanel Git Version Control,
File Manager, MultiPHP INI Editor, and phpMyAdmin**. Hosting does **not**
require a shell, SSH Terminal, Docker, MySQL CLI, Composer CLI, cron jobs, or workers.
The Mac is used for Git operations only in the deployment procedure.

- Git repository: `/home/arsamnet/repositories/opgateway`
- Document Root: `/home/arsamnet/opgateway.arsamnet.com`
- Private `.env` stays in repository root, outside Document Root.
- cPanel → Git Version Control → Update from Remote → Deploy HEAD Commit.
- `.cpanel.yml` copies **only** `public/index.php`; it preserves
  cPanel-managed `.htaccess`, `php.ini` and `.user.ini`.
- Schema installation: select `arsamnet_opgateway_db` in phpMyAdmin and
  import `database/sql/opgateway_full_schema.sql` **once** into an empty database.
  Never import into a non-empty or incorrect database.

No database operations run during Git deployment. Do not attempt to use the
MigrationRunner directly on shared hosting without an approved protected UI.
See [phpMyAdmin installation](docs/phpmyadmin-install.md).

## Documentation

- [phpMyAdmin installation](docs/phpmyadmin-install.md)
- [Database design](docs/database.md)
- [Architecture](docs/architecture.md)
- [Security](docs/security.md)
- [API compatibility](docs/api-compatibility.md)
- [cPanel deployment](docs/cpanel-deployment.md)
- [Staged Doctrine DBAL migration and mysqli diagnosis](docs/doctrine-dbal-migration.md)
- [Private diagnostic logs](docs/diagnostic-logging.md)
- [Temporary database connection test](docs/db-connection-test.md)

No secrets, GitHub Actions, or CI/CD workflows are included.
