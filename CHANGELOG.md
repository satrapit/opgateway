# Changelog

## [Unreleased]

- Add cryptographic email verification token generation and hashed-token schema.
- Provide a second versioned phpMyAdmin-only import for shared cPanel hosting.
- Add unit tests for token format, hashing and SQL migration checksum.
- Keep email sending, registration/login routes and account activation disabled.
- Preserve cPanel-managed PHP configuration and code-only manual Git deployment.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
