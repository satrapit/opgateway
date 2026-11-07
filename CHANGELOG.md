# Changelog

## [Unreleased]

- Add read-only production schema readiness checker that refuses unknown, incomplete, mismatched or non-sequential migrations.
- Add read-only phpMyAdmin preflight SQL for the installed first schema and subsequent identity/security tables.
- Document ordered, backup-first import of migrations 002 and 003 without recreating the confirmed production database.
- Do not auto-run migrations, expose admin routes, or create any GitHub workflow.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
