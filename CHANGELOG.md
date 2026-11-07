# Changelog

## [Unreleased]

- Add validated MySQL/MariaDB connection settings and an on-demand PDO factory.
- Add tests for DSN construction and malicious/invalid configuration values.
- Document the shared-hosting database strategy and planned protected migrations.
- Preserve cPanel-managed `.htaccess` and PHP settings during deployment.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add repeatable Composer lockfile, secure defaults and local tests.
- Verify health endpoint and denied upstream access on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation with local test scaffolding.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no upstream proxy, authentication or tenant isolation.
