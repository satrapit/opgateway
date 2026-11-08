# Changelog

## [Unreleased]

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
