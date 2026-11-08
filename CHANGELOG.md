# Changelog

## [Unreleased]

- Preserve valid numeric MySQL errors even when their codes are not in
  the categorized allowlist; return MySQLi failure stage and code for
  gated, read-only diagnostics without raw exception messages.


- Fix database test 404 caused by cPanel deployment copying only
  public/index.php: add a disabled-by-default guarded virtual route.
- Emit private, allowlisted gate-denial reason so 404 can be diagnosed
  without leaking client IPs or configuration values.
- Remove need to publish temporary diagnostic PHP files in Document Root.


- Add an opt-in, IP- and HTTPS-restricted temporary database-only
  connection test for cPanel File Manager deployment (not auto-published).
- Test private .env PDO and native MySQLi connectivity with SELECT 1;
  never expose raw connection errors, credentials or request data.

- Add a PHP 8.3 Doctrine DBAL factory with `pdo_mysql` and `mysqli`
  drivers plus DBAL login, registration and administrator-authorization
  adapters using strictly parameterized queries.
- Add default-disabled, read-only native MySQLi connection probe gated
  behind setup's existing HTTPS, IP and private configuration checks.
- Log only a finite probe result alongside existing PDO connection
  diagnostics, with no credentials or SQL exposed.
- Keep all live PDO routes unchanged until Composer dependencies, lockfile
  and cPanel vendor package can be verified.
- No SQL migration, workflow or automatic production activation.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
