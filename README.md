# OPGateway

Security-first, PHP 8.3-compatible, multi-tenant Openprovider API Gateway (initial foundation only).

> **Not production-ready.** There is no authenticated client API, tenant isolation, upstream transport, or installer. All `/v1` endpoints deliberately return HTTP 503. Do not deploy as a functioning Openprovider proxy.

## Current scope

- Minimal HTTP entrypoint and non-sensitive `GET /health`.
- Fail-closed gateway route placeholder.
- Exact IPv4/IPv6 matching helper (not yet connected to middleware).
- Strict simple `.env` loader, allowlisted metadata structure, PHPUnit scaffold.
- Version-controlled **manual** cPanel deployment of only `public/index.php` and `public/.htaccess`.

## Local development

```sh
cp .env.example .env
php scripts/lint.php
php scripts/smoke.php
composer validate --no-check-publish
composer install
composer test
php -S 127.0.0.1:8080 -t public public/index.php
```

## Deployment paths for this instance

- Repository: `/home/arsamnet/repositories/opgateway`.
- Document root: `/home/arsamnet/opgateway.arsamnet.com`.
- `.env` is private and must remain in the source repository, outside the Document Root.
- Deploy by cPanel → Git Version Control → **Update from Remote**, then **Deploy HEAD Commit**. No SSH/Terminal access to cPanel is needed.

See [cPanel deployment](docs/cpanel-deployment.md) and [security model](docs/security.md).

## Versioning

Foundation versions use Semantic Versioning prerelease identifiers (e.g. `v0.1.0-alpha.1`, `v0.1.0-alpha.2`), Git annotated tags, and `CHANGELOG.md`. Never tag a release until local tests pass.

## Documentation

- [Architecture](docs/architecture.md)
- [API compatibility](docs/api-compatibility.md)
- [Testing](docs/testing.md)
- [Security](docs/security.md)

No GitHub Actions, CI/CD workflows, or secrets are included.
