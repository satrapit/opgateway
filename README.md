# OPGateway

PHP 8.3 / MySQL Openprovider gateway for cPanel shared hosting, with a
TailAdmin/Twig admin panel and a self-service account portal.

> **Unreleased feature branch:** `main` / production remains at
> v0.1.0-alpha.3. Do not deploy or merge this branch before live SMTP,
> registration, IP, provider, security and browser integration testing.
> The local database has migration 004; it needs migration 005 next. Production
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
`DB_PASSWORD`). All global application settings live in a single key/value `settings`
table (`setting_key`, `setting_value`, `updated_at`), including encrypted
`smtp.password`. The `admin` role manages SMTP; individual owner credentials
remain separate in `provider_connections` for access control. The application has no `*_ENABLED` switches.
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
   For an existing alpha.3 DB, apply 004 then 005 in order after backup.
   For the current local DB already at 004, run **only** reviewed
   `database/sql/upgrade_202611100002.sql` (005).
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
