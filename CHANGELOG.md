# Changelog

## [Unreleased]

- Add a versioned, import-once phpMyAdmin SQL bundle matching the first PHP migration checksum.
- Document cPanel-only hosting workflow and eliminate Docker/Terminal dependencies from the deployment instructions.
- Retain Git deployment as code-only; never run database migrations automatically.
- Keep all provider API routes blocked until authentication and tenant isolation are implemented.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
