# Frontend architecture and delivery plan

## Verified state (2026-11-09)
OPGateway at commit `8f8ab330145b05c7dbd0dd8dd9ff1b8e73771bb7` uses two PHP HTML-generating classes, `BrowserViews` for admin and `AccountViews` for owner. `PrivateBrowserAdapter` calls static view methods only after trusted HTTPS/IP checks and revalidated admin status. Forms POST to existing `/admin/login`, `/admin/review`, `/admin/provision`, `/admin/logout`. `AdminOverviewRepository::pending()` returns at most 50 rows in each of five queues. There are no runtime UI assets, Twig templates, or frontend build system. `/v1` remains denied; administrator and account flags default off.

## TailAdmin Free baseline
Reference: [TailAdmin Free source](https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template) (MIT, copyright 2023 TailAdmin). The reviewed `package.json` identifies itself as version 2.4.0, Tailwind CSS `^4.3.3`, Alpine `^3.17.2`, Webpack 5 and `@tailwindcss/postcss`. The README separately describes a 2.5.0 changelog; never infer the package version from that prose alone. Source `src/css/style.css` uses `brand-500 #465fff`, a neutral grayscale, `Outfit` typography and muted shadows. Use no Google Fonts runtime request; use a local font only if separately licensed and bundled. Preserve TailAdmin MIT notice for copied substantial assets.

## Incremental migration
1. Add root `AGENTS.md` and project-specific UI design/architecture docs, identify route contracts and security boundaries.
2. Integrate Twig 3 via Composer locally; commit a resolved, reproducible `composer.lock` **before** enabling any Twig route. Add a strict autoescaping renderer and shared layouts/components.
3. Introduce admin Twig rendering behind an **additional default-off view migration flag**; existing `BrowserViews` continues as legacy fallback while adapter methods, auth and POST contracts remain unchanged.
4. Build a minimal tailored Tailwind/Alpine CSP-safe Webpack entry locally, commit only minimal production CSS/JS assets and deploy them through a restrictive allowlist.
5. Provide mobile-first admin login and five real-data dashboard sections (pending user approvals, eligible owners, client lifecycle, pending IP and approved IP), with state-aware action forms. Summary metrics must describe *visible bounded rows* unless separate authorized count queries are added.
6. Validate all regressions and manual cPanel behavior before opting into Twig rendering; then retire legacy admin HTML and old `unsafe-inline` CSP only after the account portal is similarly migrated.

## Template conventions
- `templates/layouts/`: auth and operator shell; `templates/components/`: safe form/section/status patterns; `templates/admin/`: login, dashboard and section includes.
- `src/View/`: renderer plus presentation-only view model transformation; never query DB in templates.
- View model keys are explicit typed arrays, not arbitrary domain objects; do not pass PDO or credentials.
- Sidebar anchors must correspond to real dashboard sections; future routes are not clickable.

## Assets / CSP
- `frontend/`: locally built Tailwind CSS v4 and Alpine CSP-compatible JavaScript, Webpack production build. No JS is necessary for server form semantics.
- `public/assets/admin/`: reviewed compiled artifacts only. A page must not opt into the new view flag until the assets are built and present on the server.
- Legacy account HTML contains inline CSS; global CSP must preserve that until the account portal is separately migrated. New admin Twig views should request a stricter route-specific CSP where supported.
- Light/dark/system theme preference may be stored client-side with a non-sensitive key; no token, password, CSRF or session IDs.

## Deferred features
Search/filtering over unseen results, pagination, global metrics, API request logs, security event reports, usage charts, MFA, public signup and upstream endpoints are not yet implemented. Do not add dead navigation or placeholder data for them.

## Verification
Run `php scripts/lint.php`, `./vendor/bin/phpunit --display-phpunit-notices`, `php scripts/smoke.php`, `composer validate --strict --no-check-publish`, `npm ci`, `npm run build`; then browser-test 320, 375, 390, 768, 1024, 1440px, keyboard, system/light/dark, form CSRF, allowed/disallowed IP, escaping and deployment paths. Mark every result as **unverified** until actually executed.
