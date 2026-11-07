# Changelog

## [Unreleased]

- Wire administrator sign-in, read-only landing page and logout through a default-disabled HTTP entry.
- Require exact operator IP and trusted HTTPS, complete schema history and a dedicated rate-limit key.
- Connect MySQL-backed login throttling and metadata-only security events to the private browser flow.
- Preserve default-disabled first-admin setup and deny-by-default provider routes.
- Add early-rejection tests and deployment guidance; no SQL, workflows or hosted shell.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
