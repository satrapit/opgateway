# Changelog

## [Unreleased]

- Check all three schema migrations and the empty first-admin identity
  state before rendering the opt-in installation form.
- Preserve transactional repeat checks under the MySQL installation lock.
- Add a private, documented one-time setup procedure with macOS secure
  token generation and post-install read-only verification SQL.
- No additional migrations, database changes or GitHub workflows.
- Installer and admin routes remain default-disabled; /v1 remains blocked.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
