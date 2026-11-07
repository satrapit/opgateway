# OPGateway

Security-first PHP 8.3 Openprovider API Gateway — currently an early foundation.

> **Not production-ready.** No client authentication, tenant isolation, upstream
> transport or operational installer is enabled. All `/v1` paths return HTTP 503.

## Implemented foundation

- Non-sensitive `GET /health` and fail-closed upstream routes.
- Minimal PHP bootstrap for split cPanel repository and Document Root.
- Exact IP matching helper (not yet enforced by middleware).
- Private `.env` loader, safe logging metadata, validated DB settings and an
  on-demand PDO factory (no database connection on a normal request).
- Local tests and manual cPanel Git Version Control deployment.

## Local checks

```sh
cp .env.example .env
php scripts/lint.php
php scripts/smoke.php
composer validate --strict --no-check-publish
composer install
composer test
```

## Manual cPanel deployment

- Source: `/home/arsamnet/repositories/opgateway`.
- Document Root: `/home/arsamnet/opgateway.arsamnet.com`.
- Private `.env` remains outside Document Root.
- Use cPanel Git Version Control → Update from Remote → Deploy HEAD Commit.
- The deployment manifest copies **only** `public/index.php`, preserving
  cPanel-managed `.htaccess`, `php.ini`, and `.user.ini`.

## Documentation

- [Database design](docs/database.md)
- [Architecture](docs/architecture.md)
- [Security](docs/security.md)
- [API compatibility](docs/api-compatibility.md)
- [cPanel deployment](docs/cpanel-deployment.md)
- [Testing](docs/testing.md)

No secrets, GitHub Actions workflows, CI/CD or auto-deployment are included.
