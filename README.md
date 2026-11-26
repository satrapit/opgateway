# OPGateway

PHP 8.3 / MySQL Openprovider gateway for cPanel shared hosting, with a
shared TailAdmin/Twig design system for both admin and self-service account portals.

> **Development release candidate (2026-11-26):** `main` includes Google
> onboarding, shared account profiles, the CAPTCHA safeguards, and optional
> Gmail inbox verification from merged PR #13. The next proposed tag is
> `v0.1.0-alpha.5`. No production deployment is implied. The earlier
> `v0.1.0-alpha.4.1` tag is a rollback-compatible pre-Google baseline,
> **not** a rollback target for all later schema migrations.

## One application, no feature-flag maze

- `/admin`: TailAdmin/Twig only; existing `admin` role manages user
  approvals, API clients, pending IPs, and `/admin/settings/smtp`.
- `/account`: TailAdmin/Twig login, registration, verification, dashboard,
  one-time key display and owner-specific
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
remain separate in `provider_connections` for access control. Authorization
rules cannot be disabled by feature flags. The optional
`smtp.gmail_actions_enabled` flag defaults to off and affects only Gmail
verification markup and its dedicated one-click endpoint.
Security, Google sign-in and SMTP configuration are managed in authenticated
administrator settings with session CSRF protection.
See [configuration locations and .env cleanup](docs/configuration.md)
and [authentication settings](docs/authentication-settings.md).

The canonical HTTPS host comes from the configured web-server
`SERVER_NAME`, which must match `HTTP_HOST`. A unique AES-256-GCM
application secret is stored in private `storage/app-secret.key` (mode
0600), outside the public document root. It is created once and **must be
backed up**; never save this key in MySQL, Git or the public directory.

## Development

1. Run `composer install` on PHP 8.3 with MySQL extensions.
2. Copy `.env.example` to private `.env` and provide **local** DB credentials.
   If upgrading a local checkout, remove retired non-DB entries from .env.
3. For an **empty disposable development database only**, use the full schema
   with nine completed migration records. For any existing database, back up
   first and apply only the reviewed missing migrations in sequence; the
   current account profile code requires migration 009. Never import the full
   schema over existing data, and never run migrations automatically in
   production. See [deployment](docs/cpanel-deployment.md).
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


### Single-domain Google rollout

The only cPanel deployment target is `https://opgateway.arsamnet.com`;
there is no separate staging hostname. The approved Google OAuth redirect URI
is `https://opgateway.arsamnet.com/account/google/callback`.

The `main` branch includes Google-first onboarding, passwordless accounts,
mailbox-confirmed collision handling, a separate Account Security page and
optional Gmail inbox `ConfirmAction` for signup and invitation emails. Gmail
inbox actions default to **disabled** until sender validation and Google
registration are complete; normal email verification remains available.

Before **any** manual deployment, verify a database and private-key backup,
check the production migration ledger against the nine-schema-migration
requirement, and rehearse a compatible rollback. Alpha.4.1 is not a safe
rollback against later schemas without additional compatibility work.
Browser/Google, SMTP and database integration acceptance remain outstanding
even after local unit, smoke and frontend test gates pass. See
[deployment guidance](docs/cpanel-deployment.md),
[testing](docs/testing.md) and
[Gmail inbox verification](docs/gmail-inbox-verification.md).
