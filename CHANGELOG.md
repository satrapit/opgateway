# Changelog

## [Unreleased]

- Add high-entropy API credential generation, hashed storage and one-time issue semantics.
- Add SQL-enforced owner-scoped credential issuance and revocation.
- Add fail-closed authentication checking active client/owner and approved IP whitelist.
- Add unit tests and API credential security documentation.
- No schema changes, hosted CLI, public endpoints or GitHub workflows introduced.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
