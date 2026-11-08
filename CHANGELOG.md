# Changelog

## [Unreleased]

- Unify the concurrent TailAdmin/Twig implementations on a single injected admin page renderer and `/assets/admin/` build pipeline; require local assets and private Twig before explicit UI opt-in, add login theme selection and bounded cPanel deploy allowlist. Rebuild and visual/security verification are pending.

- Begin a default-disabled Twig 3/TailAdmin Free admin UI migration with a private strict-autoescaping renderer, TailAdmin Free-inspired auth/dashboard templates, five real bounded review queues, reusable unchanged POST contracts, CSP-safe Alpine/webpack source, and focused adapter/template tests. The legacy admin view remains the default until dependencies, locks, assets and browser testing are complete.
- Add permanent `AGENTS.md` agent standards, UI architecture, visual tokens and build/deployment migration documentation; no workflow or automatic server build added.

- Add default-off approved-owner account portal with secure password login, owner-scoped nonsecret client/key/IP listings, and separate credential operations flag.
- Implement one-time API key issuance with password reconfirmation, CSRF rotation, owner-scoped SQL, HMAC throttling and transactionally recorded metadata-only audit; support owner key revocation.
- Add a guarded local-only test account password reset command and focused security regression tests; keep `/v1` provider routes unavailable.

- Add administrator-initiated client disabling and exact IP revocation, with fresh SQL role checks, audited action authorization and protected approval lists. Disabling a client or removing an IP entry invalidates API key admission immediately.

- Add administrator onboarding forms for creating disabled API clients for approved users and requesting exact disabled IP allowlist entries; reuse default-off action flag, CSRF, HMAC throttling, SQL role checks and transactional metadata-only audit.
- Extend admin dashboard to show eligible owners and active clients for provisioning; add related regression tests.

- Fix MySQL error 1093 in administrator user approval by replacing a same-table UPDATE subquery with a role-checked self-join; add focused regression tests.

- Add optional, default-disabled administrator review actions for verified user approval, eligible client activation and pending IP approval, using CSRF-protected forms and live role checks.
- Add HMAC-isolated MySQL mutation throttling and transactional metadata-only security audit, with fail-closed errors and no schema change.

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
