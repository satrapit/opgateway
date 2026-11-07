# Changelog

## [Unreleased]

- Add an explicit disposable MySQL 8.0 integration test for the migration runner.
- Test actual schema creation, no-op repeat execution, and advisory lock contention.
- Document local Docker database setup without storing secrets.
- Keep installer and production migration execution disabled.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
