# OPGateway

Security-first, PHP 8.3-compatible, multi-tenant Openprovider API gateway (initial foundation only).

> **Not production-ready.** This initial implementation contains no authenticated client API, tenant isolation, upstream transport or installer. `/v1/*` deliberately returns HTTP 503. Do not deploy it as a functioning proxy.

## Current implementation

- Minimal PHP HTTP entry point and non-sensitive `/health` endpoint.
- Fail-closed gateway route placeholder.
- Exact IPv4/IPv6 matching helper (not yet connected to middleware).
- Strict simple `.env` loader, defensive minimal logging metadata, PHPUnit test scaffolding.
- Architecture and security ADRs.

## Local development

```sh
cp .env.example .env
php scripts/lint.php
php scripts/smoke.php
composer install
composer test
php -S 127.0.0.1:8080 -t public public/index.php
curl -i http://127.0.0.1:8080/health
```

## Version and cPanel Git updates

Current prerelease: `v0.1.0-alpha.1`. See [cPanel Git Version Control instructions](docs/cpanel-deployment.md). The source can be updated with **Update from Remote** when the subdomain document root is `<repo>/public`; **Deploy HEAD Commit** is intentionally unsupported because this repository contains no `.cpanel.yml`. Full production usage is **blocked** until authentication, installer, tenant isolation and contract verification are complete.

No GitHub Actions, CI/CD or auto-deployment files are included. Never commit secrets or `.env`.

## Source of truth

- https://developer.openprovider.com/get-started.html
- https://developer.openprovider.com/api-keys.html
- https://developer.openprovider.com/rest-api-documentation.html

See [API compatibility](docs/api-compatibility.md) for the documented `/v1` vs legacy `/v1beta` divergence.
