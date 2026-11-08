# OPGateway

A security-first PHP 8.3 / MySQL gateway foundation for the Openprovider API on shared cPanel hosting.

**Status:** Administrator login/logout and bounded review queues are available when explicitly enabled. Three administrator approvals are implemented but independently disabled by default; the API client and ownership modules exist internally, but **all public `/v1` provider routes remain disabled (HTTP 503)**. This is not a production-ready upstream proxy.

## Hosting model

- Private source and `.env`: `/home/arsamnet/repositories/opgateway`
- Public Document Root: `/home/arsamnet/opgateway.arsamnet.com`
- Deployment: cPanel → Git Version Control → **Update from Remote** → **Deploy HEAD Commit**.
- `.cpanel.yml` copies only `public/index.php`; it never overwrites cPanel-managed `.htaccess`, `php.ini`, `.user.ini` or private configuration.
- Hosted MySQL uses `DB_HOST=db`; on local macOS, use the actual MySQL endpoint (often `127.0.0.1`, port `3306`). A local database containing the same data still needs independent connection settings.
- `/health` returns `{"status":"ok"}`, without checking database connectivity.

## Quick start (local)

1. Run `composer install` on PHP 8.3 with PDO MySQL.
2. Create a private `.env` from `.env.example` and supply real local DB settings. Never commit it.
3. For an **empty new database only**, import `database/sql/opgateway_full_schema.sql`. Existing databases must **not** be re-imported.
4. Create the first administrator using the reviewed one-time SQL in [Database](docs/database.md), if none exists.
5. Enable the admin browser only after configuring its exact local IP, HTTPS URL and random login limiter key. See [Administration](docs/admin.md).
6. Run `php scripts/lint.php`, `composer test` and `php scripts/smoke.php`.

There is no public setup endpoint or automated schema migration on deploy.

## Documentation

- [Architecture and current feature boundaries](docs/architecture.md)
- [Administration and login](docs/admin.md)
- [Database and first-administrator SQL](docs/database.md)
- [Security and API credential rules](docs/security.md)
- [Deployment and diagnostics](docs/cpanel-deployment.md)
- [Testing](docs/testing.md)
- [Provider API compatibility](docs/api-compatibility.md)

Project policy: [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), [CHANGELOG.md](CHANGELOG.md). No GitHub Actions, automatic hosting workflows, credentials, or runtime logs are committed.
