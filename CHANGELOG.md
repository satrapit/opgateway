# Changelog

## [Unreleased]

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
