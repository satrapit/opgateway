# Changelog

## [Unreleased]

- Add private, metadata-only JSONL diagnostic logging for setup/admin and
  unhandled HTTP exceptions, correlated with the X-Request-ID header.
- Distinguish setup config, database/schema, session and provisioning
  failure stages without writing exception messages or secrets.
- Add redaction test and cPanel File Manager instructions.
- No database changes, new migrations or GitHub workflows.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
