# Changelog

## [Unreleased]

- Add private browser login/logout controller requiring guarded authentication, session CSRF and fresh admin authorization.
- Add dependency-free, escaped HTML login and dashboard renderers, with tests.
- Preserve production public routing and existing database; no new SQL or enabled admin URLs.
- Document explicit HTTP transport and storage prerequisites before enabling the browser UI.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
