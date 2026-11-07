# Changelog

## [Unreleased]

- Harden first-admin setup with atomic MySQL attempt throttling: maximum five
  attempts per source IP in 15 minutes, before setup-token validation.
- Reject incomplete schema history before installation.
- Require a separate high-entropy setup rate-limit key in private .env.
- Reuse the already installed login_rate_limits table; no database migration
  or SQL import required.
- Leave installer and admin UI disabled by default; provider routes blocked.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
