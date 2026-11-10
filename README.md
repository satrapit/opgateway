# OPGateway

PHP 8.3 / MySQL Openprovider gateway for cPanel shared hosting, with a
TailAdmin/Twig admin panel and a self-service account portal.

> **Unreleased feature branch:** `main` / production remains at
> v0.1.0-alpha.3. Do not deploy or merge this branch before live SMTP,
> registration, IP, provider, security and browser integration testing.
> The local database was manually upgraded to migration 004; production
> must never be upgraded without a verified backup and deployment plan.

## One application, no feature-flag maze

- `/admin`: TailAdmin/Twig only; existing `admin` role manages user
  approvals, API clients, pending IPs, and `/admin/settings/smtp`.
- `/account`: login, registration with email verification, owner-specific
  Openprovider credentials, disabled client creation and IP requests,
  one-time API key issuance/revocation.
- `/v1/*`: gateway-issued bearer authorization, exact approved source IP,
  active client and owner validation, bounded fixed-host HTTPS relay.
- Mutating/paid Openprovider operations remain **unavailable in code** until
  a verified per-operation authorization design is released. Domain
  availability checks may use a non-mutating POST.

Configuration is deliberately small. **Only MySQL connection values belong in
`.env`** (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
`DB_PASSWORD`). SMTP settings are encrypted and managed in the database,
through the `admin` role. The application has no `*_ENABLED` switches.
Security policy is enforced by authentication/authorization, not runtime flags.

The canonical HTTPS host comes from the configured web-server
`SERVER_NAME`, which must match `HTTP_HOST`. A unique AES-256-GCM
application secret is stored in private `storage/app-secret.key` (mode
0600), outside the public document root. It is created once and **must be
backed up**; never save this key in MySQL, Git or the public directory.

## Development

1. Run `composer install` on PHP 8.3 with MySQL extensions.
2. Copy `.env.example` to private `.env` and provide **local** DB credentials.
   If upgrading a local checkout, remove retired non-DB entries from .env.
3. For an empty DB import `database/sql/opgateway_full_schema.sql` once.
   For an existing alpha.3 DB, back up and run only reviewed incremental
   migration `database/sql/upgrade_202611100001.sql` once.
4. On first run create an admin using the existing reviewed SQL in
   [database documentation](docs/database.md), if none exists.
5. Use HTTPS at a correctly configured canonical virtual host.
6. Run `php scripts/lint.php`, `./vendor/bin/phpunit` and
   `php scripts/smoke.php`, then build frontend assets locally.

Deployment is manual through cPanel Git Version Control and
`.cpanel.yml`; only reviewed static assets/index.php are copied into
Document Root. No GitHub Actions, auto migrations or server-side Composer.

## Documentation

- [Architecture](docs/architecture.md)
- [Administrator guide](docs/admin.md)
- [Account and API keys](docs/account.md)
- [Onboarding and release checklist](docs/gateway-onboarding.md)
- [Database](docs/database.md)
- [Security](docs/security.md)
- [Hosting and diagnostics](docs/cpanel-deployment.md)
- [API compatibility](docs/api-compatibility.md)
- [Frontend and design](docs/frontend-architecture.md)
- [Testing](docs/testing.md)
