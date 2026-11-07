# Changelog

## [Unreleased]

- Add internal PDO-backed login eligibility and password authentication.
- Add secure browser session initialization, session ID rotation and CSRF support.
- Recheck account status during protected session access; expire authentication after 30 minutes.
- Add login authentication unit tests and security integration documentation.
- Preserve all existing schemas, cPanel configuration and fail-closed API routes.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
