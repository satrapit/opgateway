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
- Production MySQL hostname on this provider: **`db`**, not `localhost`.
  Set `DB_HOST=db` in the private `.env` together with the *actual* database
  name and assigned user. Do not assume previous database names are current.
- Schema installation for a **new empty database only**: import
  `database/sql/opgateway_full_schema.sql` once. Never import over the
  existing migrated database.

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
- [Single PDO database architecture](docs/pdo-connection.md)
- [Private diagnostic logs](docs/diagnostic-logging.md)

No secrets, GitHub Actions, or CI/CD workflows are included.

**Database access:** Native PDO only, using one factory and prepared statements in every repository. No external ORM or query builder is required.

**Diagnostics cleanup:** The former HTTP database-test route and PHP file were removed. The deployment deletes only the two known temporary entrypoints `db-connection-test.php` and `mysql-direct-test.php` from Document Root. Remove any separately stored temporary private diagnostic credentials via cPanel File Manager.
