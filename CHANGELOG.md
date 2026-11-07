# Changelog

## [Unreleased]

- Add internal MySQL-backed pre-login throttling with per-IP and per-identity HMAC counters.
- Add allowlisted, metadata-only security event logging.
- Add guarded login orchestration with tests that ensure throttling precedes password verification.
- Stage separate phpMyAdmin SQL for future security support tables; no automatic or production import.
- Keep login and provider API routes disabled until administration and security controls are complete.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
