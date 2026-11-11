# OPGateway

PHP 8.3 / MySQL Openprovider gateway for cPanel shared hosting, with a
shared TailAdmin/Twig design system for both admin and self-service account portals.

> **Release boundary:** tagged `v0.1.0-alpha.4.1` is the tested
> pre-Google rollback base with six required migrations and explicit
> compatibility for migration 007. The current `main` also includes the
> subsequent database-only .env cleanup. Google sign-in is still in
> Draft PR #4. The replacement TailAdmin menu, image CAPTCHA and
> creation workflows are being reviewed in the independent branch
> `feat/admin-settings-users-image-captcha`. Neither is deployed.

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
remain separate in `provider_connections` for access control. The application has no `*_ENABLED` switches.
Security policy is enforced by authentication/authorization, not runtime flags.
Under development: TailAdmin-style Settings parent with separate
`/admin/settings/security`, `/admin/settings/login/google` and
`/admin/settings/smtp` pages.
Google SSO and password-login CAPTCHA are configured in MySQL by an
authorized administrator with current-password confirmation.
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
3. For an empty database on the Google development branch, import the
   complete schema once (7 migration records), but only after confirming it
   has no existing data. For an existing alpha.3 database, alpha.4 requires
   applying 004, 005 and 006 in order after a verified backup. The Google
   feature requires migration 007 in addition, tested locally first.
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

The only cPanel deployment is `https://opgateway.arsamnet.com`. There
is no staging hostname. Before migrating its database to 007, verify and
deploy the **tagged** rollback-compatible pre-Google alpha.4.1
release first. Verify the production backup, apply 007 once,
then deploy the locally tested Google feature from a reviewed main merge.
The original alpha.4 code will reject a database already on migration 007;
only alpha.4.1 can serve as the code-only fallback after that upgrade.
Use the exact OAuth redirect URI
`https://opgateway.arsamnet.com/account/google/callback`.
