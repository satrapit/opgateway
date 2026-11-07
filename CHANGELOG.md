# Changelog

## [Unreleased]

- Add stage-specific MySQL diagnostics separating driver availability, PDO
  connection, migration inspection, and identity lookup failures.
- Add reviewed MySQL errno and SQLSTATE-class classifications without
  logging raw exceptions, credentials, SQL, IPs, headers or payloads.
- Preserve correlation through X-Request-ID and private JSONL log.
- Add tests for classification and redaction and update CloudLinux PHP
  Selector troubleshooting documentation.
- No new SQL, migration, workflow or default-enabled public endpoint.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
