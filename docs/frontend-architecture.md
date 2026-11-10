# Frontend architecture and delivery plan

## Verified state (2026-11-09)
OPGateway at commit `8f8ab330145b05c7dbd0dd8dd9ff1b8e73771bb7` uses two PHP HTML-generating classes, `BrowserViews` for admin and `AccountViews` for owner. `PrivateBrowserAdapter` calls static view methods only after trusted HTTPS/IP checks and revalidated admin status. Forms POST to existing `/admin/login`, `/admin/review`, `/admin/provision`, `/admin/logout`. `AdminOverviewRepository::pending()` returns at most 50 rows in each of five queues. There are no runtime UI assets, Twig templates, or frontend build system. `/v1` remains denied; administrator and account flags default off.

## TailAdmin Free baseline
Reference: [TailAdmin Free source](https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template) (MIT, copyright 2023 TailAdmin). The reviewed `package.json` identifies itself as version 2.4.0, Tailwind CSS `^4.3.3`, Alpine `^3.17.2`, Webpack 5 and `@tailwindcss/postcss`. The README separately describes a 2.5.0 changelog; never infer the package version from that prose alone. Source `src/css/style.css` uses `brand-500 #465fff`, a neutral grayscale, `Outfit` typography and muted shadows. Use no Google Fonts runtime request; use a local font only if separately licensed and bundled. Preserve TailAdmin MIT notice for copied substantial assets.

## Current implementation status (staged, not validated)
The private `src/View/` Twig renderer, `templates/layouts`, login/dashboard Twig templates, reusable POST form macros, default-off `ADMIN_TWIG_UI_ENABLED` switch, separate CSP selection and `frontend/` Tailwind/Alpine CSP source have been authored. Existing domain services and strict POST contracts remain in place. **Twig has not been installed in this execution environment, the Composer lock is not yet updated, and the production assets have not been built or deployed.** These steps are required before enabling Twig; the current legacy admin HTML remains functional while the new flag is false.

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

## Required local dependency and asset finalization

On the developer's trusted Mac (PHP 8.3+, Composer and Node 20+):

```bash
cd ~/Sites/opgateway
git pull --ff-only origin main
composer update twig/twig --with-all-dependencies
cd frontend
npm install
npm run lint
npm run build
cd ..
php scripts/lint.php
./vendor/bin/phpunit --display-phpunit-notices
php scripts/smoke.php
composer validate --strict --no-check-publish
git status --short
```

Review and commit the generated `composer.lock`, `frontend/package-lock.json` and **only** `public/assets/admin/app.css`, `app.js`, `theme-init.js` after checking for `sourceMappingURL`, third-party runtime URLs and secrets. Do not enable `ADMIN_TWIG_UI_ENABLED` before these artifacts are committed and a restricted cPanel asset deployment allowlist has been added and verified. No server-side npm/composer commands may be required. On cPanel, private `vendor/` must be prepared from the lock on a matching PHP environment and transferred to the private checkout via File Manager or other approved out-of-band process, never to Document Root. The static bundles must be staged in a controlled allowlist. In local testing, the new flag can be enabled only after the dependencies and asset files are ready; test separately from Production.

## Consolidation (2026-11-09)

The feature branch and newer `main` had different renderer and asset architectures. The single canonical implementation is the injected `AdminPageRenderer` / `TwigAdminPageRenderer`, `frontend/src/js/app.js`, and `/assets/admin/` of `main`. The earlier parallel templates, `TwigBrowserViews` and root compiled assets are not deployed. The original commits remain in history; locks and generated bundles must be rebuilt from this canonical source. The independent default-off `ADMIN_TWIG_UI_ENABLED` remains mandatory, and the HTTP entrypoint fails closed for missing private Twig or static assets after opt-in.

## Navigation, cards and icon remediation

After 2026-11-10 feedback, the Twig administrator shell uses a single
CSP-compatible Alpine component for Sidebar and accessible navigation state,
with DOM click + `hashchange` synchronization. Link navigation closes the
mobile drawer without restoring focus to the header; Escape and explicit close
return focus to the opener. Icons are selectively bundled from local Lucide
npm sources by Webpack and instantiated only from allowlisted template names;
there are no network icon requests. All five DB-backed review groups render
mobile/medium-size cards (under 1280px) and conventional tables at xl widths.
No POST names, CSRF, authorizations, state transitions or default-off switches
change. Locks and compiled assets need regeneration on the developer Mac.

## Separate admin workspace pages (2026-11-10)

The one-page anchor navigation was replaced by six explicit server-rendered
pages. `TwigAdminPageRenderer::section()` allowlists internal section
identifiers; `PrivateBrowserAdapter` allowlists GET paths and invokes the
same fresh session, role and bounded overview-query checks. The legacy
renderer continues to serve only `/admin`. Each detail template is scoped
to one queue and reuses the existing card/table classes and action macros.
`/admin` now contains only summarized, labeled visible counts and links,
not every management table. No arbitrary URL-derived Twig template path
is evaluated. Nav active state derives from the server-rendered page,
not Alpine hash tracking. The logout button has a screen-reader label
and a locally bundled icon without visible text.
