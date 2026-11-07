# Changelog

## [Unreleased]

- Add explicit opt-in HTTPS/IP-restricted first administrator setup endpoint.
- Require server-side CSRF, strict form parsing, a 256-bit out-of-band token,
  and transactional empty-database bootstrap; no default administrator.
- Ensure setup is 404 unless private configuration explicitly enables it.
- Preserve protected existing MySQL tables and default-deny /v1 and /admin.
- Add rejection tests and operator activation documentation. No new SQL,
  GitHub workflows, Mac runtime commands, or server shell requirement.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
