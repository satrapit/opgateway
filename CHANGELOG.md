# Changelog

## [Unreleased]

- Add first-administrator bootstrap services that refuse nonempty user/role tables.
- Guard setup with an out-of-band 256-bit secret digest, MySQL advisory lock and transaction.
- Add tests for valid, invalid and missing bootstrap secrets.
- Keep bootstrap unexposed: no default admin, public installer, or hardcoded secrets.
- Existing production database and cPanel deployment behavior remain unchanged.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
