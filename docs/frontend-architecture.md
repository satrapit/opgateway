# Admin UI architecture and implementation plan

## Audited starting point (main `8f8ab33`)

The protected `AdministrativeWebEntry` owns the HTTPS, exact `REMOTE_ADDR`, feature gate, PDO/migration readiness and PHP-session boundary. `PrivateBrowserAdapter` performs fresh eligibility/administrator checks before requesting bounded `AdminOverviewRepository::pending()` records. `BrowserViews` currently outputs HTML directly. `AccountViews` is a separate account boundary **outside the admin redesign scope**. See `docs/admin.md` and `docs/security.md`.

Working routes: `GET/POST /admin/login`, `GET /admin`, `POST /admin/logout`, and `POST /admin/review` / `POST /admin/provision` only when the separate mutation flag is enabled. Account and provider routes are not implicitly enabled by this work. No new operational admin URLs are required for the first slice.

## Intended dependency direction

`AdministrativeWebEntry -> PrivateBrowserAdapter -> BrowserViews -> TwigRenderer -> Twig templates`.

The existing authenticated adapter provides the prepared CSRF token, authorized bounded overview data and action enabled-state. Twig only formats this view model. Keep the adapter's strict POST parsing, auditing, CSRF and role checks unchanged. Templates do not fetch data. Use project-root-relative template paths, not public paths.

## Information architecture

- **Overview:** current authenticated administrator's bounded review queues and operational read-only notice. Queue counts denote *displayed* rows only, not global totals.
- **Verified pending users:** approve when `ADMIN_ACTIONS_ENABLED`.
- **Approved owners:** provision disabled clients when actions enabled.
- **Clients:** activate/disable and request an IP under the existing exact form contract.
- **Pending IP entries:** approve.
- **Approved IP entries:** revoke.

All operational sections may be anchored within `/admin` until corresponding verified GET routes exist. Do not link planned request logs, security events, API docs or settings without a valid authorization boundary and handler.

## Source and build

- `templates/layouts/`: admin and auth structure.
- `templates/admin/`: login and dashboard.
- `templates/components/`: reusable presentational macros/includes.
- `src/View/TwigRenderer.php`: escaped Twig render entry.
- `frontend/src/`: minimal Tailwind and local JS, no TailAdmin demo modules.
- `public/assets/`: **built-only**, explicit deployment allowlist.

The development environment runs Composer and NPM. No Composer, Webpack or Node execution on cPanel. Only after pinned `composer.lock`/vendor and minified assets are produced, tested and reviewed should the UI be deployed. The existing account portal remains unchanged.

## Rollout sequence

1. Freeze security boundary and source/contract regression tests.
2. Add Twig dependency, renderer, base layouts and TailAdmin-derived tokens.
3. Migrate **admin login** and dashboard on unchanged routes with the same POST field names.
4. Build static assets locally, update cPanel allowlist for safe public files only.
5. Run PHP tests, accessibility, browser interaction, theme and CSP checks.
6. Deploy manually after an explicit production review; never flip mutation or proxy flags as part of UI rollout.

## Risks / preflight

- Confirm the checked-in Composer lock resolves Twig and the hosting private `vendor/` is compatible before turning on templates.
- Confirm compiled assets exist in `public/assets`, and cPanel cannot publish raw source or follow untrusted symlinks.
- Existing queue queries use `LIMIT 50`; do not call them total user/client/IP counts.
- PHP-generated HTML currently uses inline CSS, and root CSP allows inline styles; the target should use self-hosted stylesheet/script sources and avoid widening CSP.
- Never claim WCAG or cross-browser success without testing real browser dimensions and keyboard interactions.
