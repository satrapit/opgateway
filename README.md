# OPGateway

A security-first PHP 8.3 / MySQL gateway foundation for the Openprovider API on shared cPanel hosting.

**Status:** Administrator login/logout, bounded review queues and an independently gated approved-owner portal with one-time API key issuance/revocation are available when explicitly enabled. Seven administrator lifecycle operations (approve user/client/IP, create client, request IP, disable client, revoke IP) are implemented but independently disabled by default; the client and credential management modules enforce ownership checks, but **all public `/v1` provider routes remain disabled (HTTP 503)**. This is not a production-ready upstream proxy.

**Admin UI migration (in progress):** When enabled locally, TailAdmin/Twig now separates management into `/admin`, `/admin/users`, `/admin/owners`, `/admin/clients`, `/admin/ips/pending` and `/admin/ips/approved`; all routes require the existing administrator HTTPS/exact IP/session/role gates. The old single-page anchor navigation is retired in this feature branch. **Do not merge/deploy before local tests and security/browser review.**

**Admin dialog enhancement (in progress):** User/owner/client/IP details use native `<details>` when JavaScript is off and local `<dialog>` when enabled. Existing create-client and request-IP forms are progressively upgraded to dialogs; destructive access changes ask for an explicit target-aware confirmation. The same server-reviewed CSRF, role, IP, and default-off mutation gates remain in charge. Browser and integrated write-action testing of this new slice are still pending.

**Admin UI migration details:** A TailAdmin Free/Twig 3 first vertical slice is in the repository behind an additional **default-off** `ADMIN_TWIG_UI_ENABLED` switch. Until the integrated Composer lock resolution, local webpack build, static asset deployment and browser/security verification are complete, keep this switch **false** in all environments. Legacy admin HTML remains the runtime default.

## Hosting model

- Private source and `.env`: `/home/arsamnet/repositories/opgateway`
- Public Document Root: `/home/arsamnet/opgateway.arsamnet.com`
- Deployment: cPanel → Git Version Control → **Update from Remote** → **Deploy HEAD Commit**.
- The staged `.cpanel.yml` copies `public/index.php` and three reviewed `/assets/admin/` files only; it never overwrites cPanel-managed `.htaccess`, `php.ini`, `.user.ini` or private configuration.
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
- [Frontend migration and build plan](docs/frontend-architecture.md)
- [TailAdmin Free design tokens](docs/ui-design-system.md)
- [Approved account portal and API keys](docs/account.md)
- [Database and first-administrator SQL](docs/database.md)
- [Security and API credential rules](docs/security.md)
- [Deployment and diagnostics](docs/cpanel-deployment.md)
- [Testing](docs/testing.md)
- [Provider API compatibility](docs/api-compatibility.md)

Project policy: [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), [CHANGELOG.md](CHANGELOG.md). No GitHub Actions, automatic hosting workflows, credentials, or runtime logs are committed.
