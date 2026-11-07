# Changelog

## [Unreleased]

- Consolidate all initial PHP migration SQL into one empty-database import
  with ten tables and three preserved migration ledger/checksum entries.
- Delete obsolete individual import and legacy preflight SQL files.
- Document safe cPanel database rebuilding and warning that PDO
  connectivity problems are not fixed by rebuilding the schema.
- Add source-level test comparing the full SQL with immutable migrations.
- No production database action, credentials or GitHub workflows.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
