# Changelog

## [Unreleased]

- Implement internal transactional email verification and one-time token consumption.
- Add verified-admin-only pending account approval service.
- Add fail-closed exact IP policy for active API clients with verified owners.
- Add unit test for token issuing and one-time consumption.
- Keep all identity/admin/API endpoints disabled until transport authentication and anti-abuse controls are complete.
- Leave the confirmed initial database and schema ledger unchanged.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
