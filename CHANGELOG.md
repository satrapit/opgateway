# Changelog

## Unreleased — Public landing and browser route normalization (2026-11-11)

- Redirect GET `/` to `/account` (HTTP 303), retaining account login and
  approval checks. Root remains unavailable for mutating methods.
- Normalize trailing slashes on explicitly supported GET Admin and Account
  pages without changing POST routes, unknown URLs or query parameters.
- Add regression coverage for every Account sidebar page after owner sign-in,
  both root redirect and route normalization; browser testing is still required.
- No schema migrations or frontend asset changes.


## Unreleased — Shared submit progress indicators (2026-11-11)

- Show an inline CSS spinner on the submitted button across Account and Admin,
  while preventing duplicate form submissions; other submit actions stay disabled.
- Preserve named submitter form semantics and accessible labels. For native
  input submit buttons, show "Please wait…" until the page changes.
- Canceled confirmations and invalid forms show no progress; browser Back
  navigation restores the original button state and text.
- Respect reduced-motion preferences and existing same-origin CSP. Build and
  browser verification remain pending.


## Unreleased — Account recovery rate-limit messaging (2026-11-11)

- Keep hourly per-email and per-IP password reset throttling unchanged.
- When recovery is throttled, return an accessible TailAdmin/Twig status page
  with HTTP 429 instead of raw JSON. No new schema or .env settings.
- Add a controller regression test for rate-limited recovery behavior.
- Local PHP/browser verification is required before deployment.

## Unreleased — Form submission and reset-link validation (2026-11-11)

- On opening a password reset URL, check the hashed token against the database
  for unused, unexpired and approved active account status; show the unavailable
  status page instead of the password form after a token is used.
- Keep the password-changing POST transaction and single-use enforcement.
- Prevent repeated browser form submissions across Account and Admin after
  native validation and any required confirmation, without changing CSRF or
  submitter field semantics.
- Local npm build, PHP unit tests and real browser verification are required.

## Unreleased — Password recovery (2026-11-10)

- Add shared Twig forgot-password and reset forms, strict CSRF and password confirmation.
- Add generic-response email requests, independent HMAC request throttling and
  hashed 15-minute single-use reset tokens sent through the existing SMTP settings.
- Add manual migration 006. No changes to migrations 001–005 or production.
- Pending local PHP/MySQL/mail/browser verification; PR remains Draft.

## Unreleased — Shared Admin/Account visual system (2026-11-10)

- Replace the Account portal's legacy inline-styled PHP HTML with
  strict autoescaping Twig templates for login, registration, verification,
  account dashboard and one-time API key display.
- Extract the shared TailAdmin workspace shell from the Admin layout;
  both portals now share versioned local CSS/JS/icons, mobile drawer,
  80px header, dark/light/system theme and accessible forms.
- Retain existing account routes, action names, CSRF, email verification,
  password rechecks, exact-IP approval and one-time-only key semantics.
- Use strict same-origin script/style CSP for Account HTML, with no inline
  CSS/JS and no runtime external assets.
- Add Account design and security regression tests; local npm build,
  PHP tests and real browser checks are **still required**.
- No migration, feature flag, new role or production rollout.


## Unreleased — Universal key/value settings (2026-11-10)

- Migration 005 creates one global `settings` table (`setting_key`,
  `setting_value`, `updated_at`), copies all SMTP settings including
  unchanged encrypted password from 004, and drops old `smtp_settings`.
- Keep migrations 001–004 and their checksums immutable. Existing local
  databases at 004 require only the new manual 005 SQL after backup.
- Update PDO SMTP storage to a single prepared multi-key upsert; retain
  admin authentication, CSRF, password validation and metadata audit.
- No new feature flags, separate SMTP table or new controller.
- Production stays unchanged; fresh PHPUnit/MySQL/browser validation required.


## Unreleased — Remove runtime feature switches and unify paths (2026-11-10)

- Restrict .env to five MySQL connection values. Keep the application crypto
  secret in an owner-only file outside Document Root, with a separate HKDF
  rate-limiter key, never in MySQL.
- Use one canonical web-server HTTPS origin, one Twig admin UI, the existing
  admin role, and always-routed owner onboarding, API key, SMTP and gateway
  admission. Store SMTP only in MySQL; require its configuration for signup.
- Retain mandatory auth, CSRF, admin/client/IP approval, audit and rate limits.
  Paid and mutating upstream operations remain unavailable.
- Fix missing password-reauthentication dependencies in provider-account save.
- Update docs and add origin/private-secret regression tests. No schema
  migration beyond previously introduced 004.


## [Unreleased]

- Begin default-off self-service account signup, email verification and SMTP administration with encrypted credentials and an additive manually-reviewed schema migration (004).
- Introduce opt-in owner Openprovider account storage, disabled client/IP request forms and a bounded, authenticated exact-IP gateway API edge. Upstream writes remain independently disabled; integration testing, composer lock and production migration are outstanding.

## [0.1.0-alpha.3] - 2026-11-10

- Keep the Sidebar logo header fixed while making the navigation links and footer independently scrollable on short desktop and mobile viewports.
- Replace JavaScript-inserted Lucide icons with first-paint SVG sprite references, preload the local sprite/script, and version all public admin assets by content hash. Use immediate system fonts without external font requests.
- Add a subdirectory-only static asset cache policy (long-term immutable URLs) without changing the cPanel-managed root .htaccess or allowing authenticated pages to be cached. Add release/asset regression tests.

- Align sidebar and content headers to 80px with matching border baselines; consolidate full-size row actions into accessible vertical-ellipsis menus on both mobile and desktop, preserving original strict form submissions.
- Allow outside/backdrop dismissal of native dialogs without posting or discarding original server-side validation, and add subtle reduced-motion-aware 130–160ms animations.
- Route successfully authorized Twig management POSTs back to their owning section through an explicit static action-to-path map (legacy POST redirect remains unchanged); add compact layout and routing tests.

- Add locally bundled, CSP-compatible record details, provisioning and destructive-action dialogs on the five independent admin pages. Native details and original POST forms remain usable without JavaScript; server-side contracts and feature gates remain unchanged.
- Add clear target context, cancel/escape/focus restoration and mobile-sized dialogs; cover strict POST form field invariants and read-only rendering in focused tests. Build/browser validation required.

- Replace the single long admin dashboard and anchor-only Sidebar navigation with six explicit, authenticated Twig workspace pages (overview, users, owners, clients, pending IPs and approved IPs). Navigation selection, title and deep links are server-driven.
- Scope bounded tables and responsive cards to one relevant detail page each; preserve the existing strict action form payloads and security feature flags. Make sign-out icon-only with its accessible label.
- Add per-route role/session/Twig scoping regression tests. Local build and browser verification are pending.

- Fix admin Sidebar link navigation: close the off-canvas drawer after selecting a section, move keyboard focus to that section and synchronize the active navigation state with URL hashes.
- Replace horizontally scrolling admin review tables on mobile/medium screens with five wrapped, responsive server-rendered record-card lists while retaining accessible tables on wide desktops and the same strict action forms.
- Adopt a single local tree-shaken Lucide icon library across the administrator shell, record summaries and form buttons; add an icon-only light/dark/system switch with no external resources. Pending lock/build/browser validation.

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
