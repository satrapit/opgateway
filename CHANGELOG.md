# Changelog

## [Unreleased]

- Add migration ledger runner with MySQL advisory lock, checksums and interrupted-DDL detection (not wired to HTTP).
- Add checksum tests and an operator-only installer security checklist.
- Define initial identity schema migrations without executing production SQL.
- Preserve cPanel-managed `.htaccess` and PHP settings.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
