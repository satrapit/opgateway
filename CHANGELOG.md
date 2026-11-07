# Changelog

## [Unreleased]

- Add internal registration data validation, password hashing, and PDO-backed pending-user insertion.
- Add unit tests for email normalization, hashing and invalid input.
- Keep registration and login HTTP endpoints disabled pending email verification, abuse prevention, and administrator approval.
- Provide a versioned, import-once phpMyAdmin SQL bundle for the initial database schema.
- Preserve cPanel-managed PHP and htaccess configuration during manual Git deployment.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
