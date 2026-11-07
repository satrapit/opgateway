# OPGateway

Security-first PHP 8.3 Openprovider API Gateway — initial non-production foundation.

Current prerelease: `v0.1.0-alpha.1`.

> Not production-ready. `/v1` and `/v1/*` return HTTP 503 until authenticated routing, tenant isolation and upstream policies are implemented.

See `docs/architecture.md`, `docs/security.md`, `docs/api-compatibility.md` and `docs/cpanel-deployment.md`.

Local checks: `php scripts/lint.php`, `php scripts/smoke.php`. PHPUnit requires local Composer installation.

No secrets, GitHub Actions, CI workflows or automatic deployment files are committed.
