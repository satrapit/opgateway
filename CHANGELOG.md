# Changelog

## [Unreleased]

- Add bounded, read-only administrator review queues for pending verified users, inactive clients and pending IP allowlist requests; keep state-changing administrative operations unrouted.

- Simplify cPanel deployment to copy only the entrypoint; remove obsolete automatic deletion of database test scripts.
- Consolidate outdated stage-specific documentation into current, maintainable references.
- Stabilize administrator browser login/logout using CSRF-protected form POSTs, safe redirects and generic errors.
- Add isolated real-session PHPUnit coverage, including wrong credentials, roles and logout CSRF; replace mock-only dependencies with stubs.
- Remove the retired first-administrator setup HTTP route and `SETUP_*` flags in favor of reviewed one-time manual phpMyAdmin SQL.
- Use a single native PDO database factory and fail-closed schema readiness checks.

## [0.1.0-alpha.2] - 2026-11-07

- Manual cPanel split-root deployment foundation, Composer lockfile, health checks and fail-closed upstream routes.

## [0.1.0-alpha.1] - 2026-11-07

- Initial PHP 8.3 foundation, architecture and security policy.

**All public `/v1` Openprovider proxy routes remain disabled.**
