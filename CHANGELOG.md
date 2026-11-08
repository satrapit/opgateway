# Changelog

## [Unreleased]

- Fix live administrator sign-in/logout: remove brittle Origin comparison
  while retaining session-bound CSRF, strict cookie policy, HTTPS, IP allowlist,
  MySQL-backed throttling and role authorization.
- Use standard browser HTTP 303 redirects after successful login/logout,
  show a generic HTML login error, and correct page referrer metadata.


- Remove the entire one-time web administrator setup mechanism, route,
  setup token, attempts limiter, and their tests and SETUP_* example flags.
- Document direct phpMyAdmin administrator provisioning with locally generated
  PHP password hash. Preserve admin sign-in and all existing database schema.


- Simplify the one-time administrator setup: remove only its unreliable
  browser Origin restriction while retaining HTTPS, exact IP, session
  CSRF, the 256-bit setup token, rate limits and the empty-database guard.
- Delete the now-unused setup Origin policy and tests. No schema changes.


- Fix root cause of browser `Origin: null` during HTML form POST by using
  `Referrer-Policy: same-origin` in the PHP front controller and Apache
  template; keep strict Origin verification, CSRF and setup-token checks.
- Document the required one-time manual change to cPanel-managed `.htaccess`.


- Differentiate rejected explicit POST Origin from missing Origin;
  accept a missing Origin only on same-origin HTML navigation indicated by
  Fetch Metadata, followed by mandatory session CSRF and setup-token checks.
- Add focused tests for the administrator setup Origin policy.


- Configure provider-specific database hostname `db` and centralize
  native PDO connection settings through DatabaseConfig.
- Remove the obsolete public DB test PHP file, its routed endpoint,
  dedicated .env flags, and socket override. Keep unit tests.
- Remove standalone public database diagnostic copies during cPanel
  deployment; leave production schema/data and private .env untouched.
- Resume secure first-admin setup with the actual migrated database.


- Remove obsolete Doctrine and MySQLi test suites after native PDO
  consolidation; preserve active PDO and migration coverage.
- Correct SQLSTATE extraction for PDO connection errors lacking errorInfo
  and categorize MySQL 1524 as authentication_plugin.
- Make migration tests compatible with the complete versioned catalog.


- Standardize every runtime database query on native PDO with one
  connection factory, shared across repositories and transactions.
- Remove Doctrine DBAL adapters, Medoo prototype, and secondary
  MySQLi connection probe.
- Keep read-only, IP-gated PDO diagnostic route and numeric MySQL errors.
- No schema changes, no new database dependency, no GitHub workflows.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
