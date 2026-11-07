# Changelog

## [Unreleased]

- Add first reviewed identity/API-client SQL schema as migration definitions only.
- Keep database migrations disabled until a secure operator-only installer and recovery mechanism exist.
- Add migration structure and deny-by-default schema tests.
- Preserve cPanel-managed `.htaccess` and PHP settings.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add repeatable Composer lockfile, secure defaults and local tests.
- Verify health endpoint and denied upstream access on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation with local test scaffolding.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no upstream proxy, authentication or tenant isolation.
