# Changelog

## [Unreleased]

- Add internal HTTP admission policy for HTTPS, authenticated admin role, same-origin POST, bounded JSON and CSRF checks.
- Add database-backed administrator authorization recheck on every privileged request.
- Add unit tests for rejected/allowed request policy decisions.
- Leave public routes and cPanel-managed configuration unchanged; no new SQL required.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
