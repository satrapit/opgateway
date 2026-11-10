# OPGateway — AI agent and contributor instructions

## 1. Project overview
OPGateway is a security-sensitive, PHP 8.3/MySQL modular monolith that will expose a restricted Openprovider-compatible API gateway. Source: `src/`, web entry: `public/index.php`, private SQL migrations: `src/Database/Migrations/`. The production gateway is not yet enabled. Read `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, `docs/architecture.md`, `docs/admin.md`, `docs/account.md`, and `docs/cpanel-deployment.md` **before edits**.

## 2. Architecture and immutable boundaries
Use PSR-4 OPGateway namespacing, strict PHP types, dependency injection where useful and native PDO. Preserve `FoundationApplication`, `AdministrativeWebEntry`, `PrivateBrowserAdapter`, `BrowserAuthController`, the dedicated account boundary, domain services and existing HTTP contracts. Views must never perform queries, business decisions, or authorization. Refactor incrementally and add regression tests; do not rewrite stable backend code for presentation purposes.

## 3. Mandatory frontend stack
TailAdmin **Free/MIT** is the only visual baseline: https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template . Preserve its brand colors (notably `brand-500 #465fff`), layout proportions, rounded cards, gray palette, spacing and understated shadows. Check the **actual** upstream commit, `package.json`, CSS tokens and license before importing assets. Do not use Pro components. Use **one shared Twig 3.x layout/theme for both Admin and Account**;
no second HTML renderer or duplicated CSS. Use Tailwind CSS v4 for styling, Alpine **CSP-safe build** only for small interactions, and minimal vanilla ES modules. Webpack compiles assets locally. No SPA framework, jQuery, remote runtime CDN, analytics, trackers, external web fonts or Node runtime on production.

## 4. MVC and Twig view layer
Keep the existing modular monolith. Put new presentation in `templates/` with layouts, components and feature templates; use `src/View/` for a focused renderer/presenter. Twig autoescaping and strict variables must stay enabled. Controllers/adapters supply pre-authorized view models and server-issued CSRF. Templates may format and branch for presentation but may not access PDO, secrets, server globals or domain services. Share cards, badges, tables, inputs, alerts and navigation through includes/macros; do not duplicate backend validation.

## 5. Mobile-first behavior
Design for widths 320, 375, 390, 768, 1024 and 1440px. No page-level horizontal overflow. Sidebar becomes an accessible off-canvas drawer with keyboard/escape and focus behavior. Use a readable desktop grid and mobile card/scroll treatment for dense tables. Form controls/tap targets should be at least about 44px tall where practical. All primary server-side actions must remain usable without JavaScript.

## 6. Theme and internationalization
Support light, dark and system preferences. The default must follow `prefers-color-scheme`; manual preference may be persisted in browser storage **only** for theme state (not credentials or tokens). Avoid flash by resolving the preference before styles when possible. Reuse TailAdmin design tokens. Do not hard-code unrelated colors in components. Current language is English, with direction-aware CSS and markup ready for future RTL translation.

## 7. Accessibility (WCAG 2.2 AA target)
Use semantic headings, navigation, labels, legends, accessible status text, keyboard focus rings, ARIA state where necessary, reduced-motion preference and safe dialogs. Do not rely on color alone. Validate disabled, loading, error, empty and success states; do not claim accessibility conformance without testing.

## 8. Real admin information architecture
Only show capabilities actually implemented: approved/verified users, approved owners, active/disabled API clients, IP whitelist requests and approvals, and authenticated/logout actions. Derive counters from real authorized queries and label bounded listings accurately. Keep future Usage, Security Events and API Docs out of live navigation until real authorized routes exist. The SMTP Settings screen is implemented and uses the existing admin role. No fake counters, endpoints, charts, filters or pagination.

## 9. Security and configuration boundaries

- **Only five DB connection values belong in private .env**; reject unknown keys. Never add flags or SMTP, provider, host or crypto settings there.
- The server virtual host supplies canonical HTTPS `SERVER_NAME`, which must match `HTTP_HOST`. Do not use forwarded headers for security decisions.
- Use the existing `admin` role for all admin operations. Do not add super-admin, dual renderers or feature toggles. All global settings are key/value rows in one MySQL `settings` table;
  SMTP uses `smtp.*` keys and encrypted `smtp.password`, edited via Admin.
- The application encryption secret is a single backed-up private file `storage/app-secret.key` with mode 0600, outside the web root; do not store its key in MySQL, .env or Git. Use separated HKDF key for HMAC rate limiting.
- Keep fresh database eligibility/roles, secure short-lived sessions, CSRF, strict form parsing, exact API-client source IP approval, HMAC rate limits, metadata-only audit and fail-closed denials. These are security checks, not optional feature flags.
- Upstream writes are not supported until narrowly scoped, reviewed operation policies exist. Never add a bypass toggle.
- Do not log SQL, raw request bodies, IPs, emails, tokens or passwords. Display new API keys only once on successful POST.

## 10. CSP and frontend interactions
Prefer a strict Content Security Policy: no `unsafe-eval`, no inline script, no external runtime origins. Audit Alpine CSP compatibility and avoid unsafe dynamic expressions. Load local JS/CSS through `public/assets/` only; changing CSP must not weaken protections for the owner portal or authenticated browser views. Escape untrusted text with Twig HTML escaping, untrusted attributes in the right context, and no `raw` filters without explicit safe review.

## 11. Build and hosting
Production is cPanel shared hosting with Git Version Control, no shell, Redis, workers, Node, composer install or asset build. Private repository checkout: `/home/arsamnet/repositories/opgateway`; public document root: `/home/arsamnet/opgateway.arsamnet.com`. Only precompiled, reviewed and committed static assets can be copied by a restricted `.cpanel.yml` allowlist. Never copy private templates, `.env`, `vendor/`, Git metadata, application logs or source into the document root. Never overwrite cPanel-managed `.htaccess`, `php.ini` or `.user.ini`. Do not introduce a GitHub Action, CI workflow, background deploy, migration on deploy or server-side NPM/Composer install.

## 12. Testing and release gates
Before implementation, inspect actual code and existing tests. Run PHP lint, PHPUnit with notices, smoke checks and strict Composer validation; verify Twig escaping, login/CSRF/IP/role/route and authorization regressions and no fake data. Run `npm ci`, production build, bundle inspection, JS lint, automated/browser responsive tests at 320–1440px, keyboard checks, dark/system theme tests and CSP checks before declaring UI ready. Keep user-verified test baselines separate from expected outcomes and accurately report what could not run.

## 13. Documentation and change management
Keep architecture, UI tokens, template conventions, security/CSP, build, manual deploy and test procedures in `docs/`; update `CHANGELOG.md` and `README.md` for meaningful changes. Use small reviewable commits, preserve the existing fixed migration checksums and avoid unrelated changes. Do not blindly copy TailAdmin's own `AGENTS.md`; this document governs OPGateway.

## 14. Definition of done
A feature is done only when it uses the actual TailAdmin Free design language, Twig presentation, accessible mobile and desktop layouts, responsive light/dark/system behavior, real authorized backend data, tested server mutations, secure local assets, compatible manual cPanel deployment, single-path routing and updated documentation. **Never describe unrun tests/builds/browser audits as passing.**


## 13. Unreleased self-service work
New registration, SMTP admin settings, Openprovider account encryption and
proxy edge are in a feature branch. Follow docs/gateway-onboarding.md,
keep unreleased operations unavailable, never edit earlier migration checksums, and
never deploy before migrations 004–005 and Composer lock/vendor tests. The existing
admin role is sufficient for all privileged administrative operations.
Do not add a super-admin role or new role migration.
