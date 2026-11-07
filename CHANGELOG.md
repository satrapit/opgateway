# Changelog

## [Unreleased]

- Add internal session-bound administrator controller and strict JSON parser.
- Add explicitly allowlisted approve-user, activate-client and approve-IP actions.
- Reject extra payload fields and derive actor identity exclusively from session.
- Retain existing protected DB authorization and fail-closed public routing.
- Add JSON validation unit tests and administrator controller documentation.
- No schema changes, new SQL, server commands or GitHub workflows.

## [0.1.0-alpha.2] - 2026-11-07

- Add manual cPanel deployment manifest for split repository/Document Root.
- Adapt bootstrap for private application source.
- Add Composer lockfile and foundational tests.
- Verify health endpoint and denied upstream routes on shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no authenticated client API or upstream transport.
