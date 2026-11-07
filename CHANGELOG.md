# Changelog

## [Unreleased]

- Align the existing login security SQL with immutable PHP migration 003.
- Register migration 003 after initial schema and email-verification migrations.
- Add checksum and security-schema tests; update prior catalog-size expectation.
- Keep production database unchanged: SQL is explicitly manual and conditional.
- Keep all administrator, authentication and Openprovider routes fail-closed.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
