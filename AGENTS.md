# OPGateway — Agent and Contributor Rules

> Mandatory instructions for Codex, AI coding agents and maintainers. Read the code and `docs/` before changing it. This repository is a PHP 8.3 modular monolith, **not** a greenfield application.

## 1. Project overview
OPGateway is a security-sensitive Openprovider API gateway designed for PHP 8.3, PDO/MySQL and shared cPanel hosting. The browser-facing admin and owner account portals are **independently gated**. All `/v1` provider endpoints currently fail closed with HTTP 503. Consult `README.md`, `docs/architecture.md`, `docs/admin.md`, `docs/account.md` and `docs/security.md` before edits.

## 2. Architecture and non-negotiable constraints
Keep PSR-4 `OPGateway\\` modules and existing HTTP routing and authorization contracts. Do not introduce a full-stack framework, SPA, ORM, Redis, queues or long-running services. Preserve existing owner credential issuance/revocation and its one-time secret display. Prefer incremental changes and small classes over reimplementing working services.

## 3. Mandatory UI stack
New server-rendered admin UI: Twig 3, TailAdmin Free visual conventions, Tailwind CSS v4, semantic HTML, accessible vanilla JS and Alpine.js **CSP build** where useful. Webpack compiles local static assets **offline**. No React, Vue, Next.js, Angular, jQuery, production CDN or Node runtime on hosting.

## 4. TailAdmin Free design system
Reference `https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template`. Inspect upstream actual CSS/package versions before copying. Preserve TailAdmin's `brand-50..950` scale (notably `brand-500 #465fff`), gray palette, layout density, cards, shadows, radii, forms, sidebar and responsive patterns. Adapt only necessary MIT-licensed free components, retain copyright notices and licenses. Never copy Pro-only features. Avoid an unrelated theme or decorative gradient.

## 5. MVC and Twig
Keep controllers and services responsible for data and business decisions. `src/View/` handles rendering and view models; templates under private `templates/` handle presentation only. Use inheritance, include/macro/components, contextual autoescaping, safe URL literals and named parameters. No SQL or privileged service calls in templates; no untrusted `|raw` output. Never embed keys, session cookies or secret payloads in template context.

## 6. Mobile-first responsiveness
Design at 320, 375, 390, 768, 1024 and 1440px. Provide a keyboard-accessible mobile drawer and coherent dense desktop tables; avoid document-level horizontal overflow. Ensure 44px-class touch targets, adequate input labels, visible actions and usable narrow-screen data cards. Prefer logical CSS properties for future RTL.

## 7. Theme behavior
Support light, dark and system preference. Store only non-sensitive preference locally. Sync `html.dark` with effective theme, honor `prefers-color-scheme`, avoid unnecessary theme flash, and test all view states in both schemes. Never store tokens or authentication state in browser storage.

## 8. Accessibility
Target WCAG 2.2 AA: semantic landmarks, accessible name/label, headings, visible focus, adequate contrast, error associations, keyboard navigation, correct dialog semantics and focus restoration, status text beyond color, and reduced-motion support. Verify before claiming compliance.

## 9. Reusable components
Use shared navigation, alerts, form controls, status badges, table/list states and confirmation patterns. Use active/disabled states from real backend state. No fake KPI, dead menu link, unauthorized action or unbounded client-side filtering. Navigation must only link to valid, authorized routes.

## 10. Authentication and authorization
Never weaken `ADMIN_UI_ENABLED`, `ADMIN_ACTIONS_ENABLED`, `ACCOUNT_UI_ENABLED`, `ACCOUNT_KEYS_ENABLED`, `GATEWAY_PROXY_ENABLED`, `ADMIN_UI_ALLOWED_IP`, secure HTTPS checks, exact `REMOTE_ADDR`, fresh role/owner eligibility checks, CSRF, password/rate limits, SQL transaction audit or fail-closed routing. Never enable a disabled flag for a visual demo, particularly in production. Admin and account security boundaries remain distinct.

## 11. Browser sessions and API credentials
Server-side sessions stay Secure, HttpOnly, SameSite=Strict and time-limited. Never put bearer credentials, CSRF values, secrets, passwords, or sensitive response bodies in logs, JavaScript, localStorage, query strings or browser consoles. API keys remain visible **once** only in their authorized POST response.

## 12. Frontend assets and CSP
Build minified CSS/JS locally and commit approved static assets as needed. Production serves only `/assets/...` from its own origin. No `unsafe-eval`, external trackers, third-party runtime CDN or avoidable inline scripts/styles. If Alpine requires expression evaluation, use `@alpinejs/csp` or an audited vanilla JS alternative. Keep strict Content Security Policy; verify third-party scripts under it.

## 13. cPanel hosting and deployment
No server SSH, Node, Composer install/build, Redis, worker or migration on deploy. Private checkout `/home/arsamnet/repositories/opgateway`; public Document Root `/home/arsamnet/opgateway.arsamnet.com`. Limit `.cpanel.yml` to explicit public files and ensure `.env`, `src`, `templates`, `vendor`, `frontend`, `storage` and Git metadata are never published. Do not overwrite cPanel-controlled `.htaccess`, `php.ini` or `.user.ini`. Existing manual Git Version Control deployment remains authoritative.

## 14. Coding standards
PHP 8.3, `declare(strict_types=1)`, PSR-12, PSR-4, typed parameters/properties, constructor injection, meaningful PHPDoc, focused units, no hardcoded secrets and clean diffs. Prefer zero unnecessary dependencies or duplicated logic. HTML should be in Twig, not long PHP heredocs.

## 15. Test requirements
Run existing `php scripts/lint.php`, `composer test`, `./vendor/bin/phpunit --display-phpunit-notices`, `php scripts/smoke.php` and `composer validate --strict --no-check-publish` locally. Test login/logout, fresh authorization, CSRF, default-off flags, escaped output, route rejection, secret non-disclosure and tenant/owner restrictions. Validate local build, CSP, responsive overflow, keyboard flow, accessible dialogs and all themes. Do not run destructive integration tests against the working database. Never claim a test ran unless it did.

## 16. Documentation requirements
Update `docs/frontend-architecture.md`, `docs/ui-design-system.md`, `docs/cpanel-deployment.md`, `docs/admin.md`, `docs/testing.md` and `README.md` as relevant. Explain exact commands, manual deployment, changed routes, security controls and limitations. Avoid copying upstream documentation verbatim.

## 17. Definition of done
A UI slice is complete only if it uses actual authorized backend data, retains security gates, has accessible responsive light/dark behavior, passes relevant PHP/JS/build/browser checks, ships **built** local assets safely, and has updated docs. A screenshot, source-only UI or mock-data page is not complete.

## 18. Agent workflow and change management
1. Review `git status`, current branch, code, tests, docs and approved scope.
2. Record implemented/disabled/planned features and security trust boundaries.
3. Propose the smallest safe vertical slice; preserve HTTP contracts and deny-by-default behavior.
4. Build TailAdmin-compatible Twig components and static asset pipeline; incrementally replace admin views.
5. Add focused negative/security tests before expanding routes.
6. Run checks, identify any checks blocked by environment, review sensitive diffs and keep commits small.
7. Document rollout, offline Composer/vendor dependencies, rollback, and verification before production.
8. Never introduce GitHub Actions/CI workflows, automatic deployment, unverified counters or unrelated architectural refactoring.

When changing permanent conventions, update this file and corresponding documentation in the same change.
