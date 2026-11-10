# Deployment and operations

## cPanel paths

- Private Git checkout: `/home/arsamnet/repositories/opgateway`.
- Document Root: `/home/arsamnet/opgateway.arsamnet.com`.
- Secrets: private checkout `.env`, outside Document Root.
- Diagnostics: private `storage/logs/opgateway.jsonl`, writable by PHP, never public.

The staged `.cpanel.yml` **copies `public/index.php` and four reviewed locally built assets and one scoped cache policy** (`public/assets/admin/app.css`, `app.js`, `theme-init.js`, `icons.svg`) plus the owned `public/assets/admin/.htaccess`. Missing/symlinked files cause deployment to fail. It checks the destination exists, `.htaccess` exists and the target index is not a symlink. It **does not delete or clean up any deployed files**. The cPanel-managed `.htaccess`, `php.ini` and `.user.ini` are not overwritten. Old standalone files, if any, must be audited and removed deliberately via File Manager, not by future deployment scripts.

## Manual deployment

1. Locally run `php scripts/lint.php`, `composer test`, `php scripts/smoke.php` and `composer validate --strict --no-check-publish`.
2. Commit and push reviewed changes to `main`; never commit real `.env`.
3. cPanel → Git Version Control → **Update from Remote**, verify commit → **Deploy HEAD Commit**.
4. Confirm `https://opgateway.arsamnet.com/health` returns HTTP 200 and `{"status":"ok"}`.
5. Confirm `/v1/domains` remains HTTP 503 with `gateway_not_configured`.
6. Check private URLs (`/.env`, `/src/autoload.php`, `/composer.json`, `/.git/config`, `/storage/`) do not return HTTP 200.
7. Confirm deployed rewrite rules and existing cPanel PHP handler remain intact.
8. Test admin browser login/dashboard/logout from the allowed IP only when `ADMIN_UI_ENABLED=true`.

No server-side shell, automated migration, Docker, cron, GitHub Actions or CI workflow is required. Roll back by a reviewed revert commit and the same manual deployment, not by resetting the deployed Git checkout.

## PHP and .htaccess

Set production `display_errors=Off`, `log_errors=On` and `APP_DEBUG=false`. Keep cPanel-generated PHP sections untouched. The application emits no-store, frame-denial, nosniff, CSP and same-origin referrer headers. Admin authentication relies on session CSRF, not on the browser `Origin` header.

For site routing, retain `DirectoryIndex index.php`, `Options -Indexes` and the existing rewrite of virtual paths to `index.php` when the request does not map to a file. Source `public/.htaccess` is only a reference, not deployed automatically.

## Private diagnostic log

The structured JSONL log records UTC time, 128-bit request ID, event code, status and restricted failure categories. Numeric MySQL codes may be present without SQL or credential details. `X-Request-ID` on HTTP responses helps correlate requests with logs.

- `admin.configuration_invalid`: missing/malformed APP_URL or `LOGIN_RATE_LIMIT_KEY`.
- `admin.database_connection_failed`: review DB host, port, credentials and grants.
- `admin.schema_inspection_failed`, `admin.schema_incomplete`: inspect migration ledger in phpMyAdmin.
- `admin.session_start_failed`: check private PHP session storage and cookie configuration.
- `http.unhandled_exception`: inspect category metadata; do not expose underlying exceptions publicly.

Typical MySQL categories: 1045 authentication failure, 1049 unknown database, 2002/2003 connection failure, 1146 missing table, 1044/1142 privilege issues, 1524 authentication plugin issue. On the current production host use `DB_HOST=db`; locally `localhost` may choose Unix socket instead of TCP `127.0.0.1`.

Do not deploy public `phpinfo`, database-probe or setup scripts. See [database.md](database.md) for schema/administrator procedures.

## TailAdmin/Twig migration (not activated)

Canonical integration uses `AdminPageRenderer` / `TwigAdminPageRenderer`, `frontend/src/js/app.js` and `/assets/admin/`. The old parallel `TwigBrowserViews` and root-level CSS/JS bundles from `e5fcb72` are superseded, not deployable.

From the consolidated branch, run Composer Twig resolution on a trusted Mac, commit the new `composer.lock`, then in `frontend/` run `npm install && npm run lint && npm run build`. Review and commit `frontend/package-lock.json` and exactly `public/assets/admin/{app.css,app.js,theme-init.js}`. The cPanel allowlist is fail-closed until these files exist. Transfer the compatible Composer `vendor/` separately into the **private** Git checkout; never put PHP vendor, source, templates, .env, or logs in Document Root. Do not run build, Composer, migrations, cron, or Node on cPanel.

Keep `ADMIN_TWIG_UI_ENABLED=false` until compiled assets and Twig are deployed privately and all regression/security/mobile/theme/CSP checks pass. Only that independent flag activates the new view layer, without changing existing account or API flags. For rollback, set it false and use an approved manual deploy.

## Static asset preloading, versioning and cache (admin UI)

A local Lucide SVG symbol sprite is built from `frontend/src/icons.svg`
to `public/assets/admin/icons.svg`, and every icon in Twig is an ordinary
`<svg><use href="/assets/admin/icons.svg?v=...#opg-icon-*"></use></svg>`.
The HTML renders icons without waiting for JavaScript, and the head preloads
the same-origin sprite and the deferred application script. There is no
external icon font, web font, runtime CDN, or additional font download.
The system font stack paints immediately; do not invent an Outfit font URL.

The private `TwigRenderer` provides content SHA-256 URL versions for four
fixed, local files. The HTML keeps `Cache-Control: no-store` because it
contains session-bound CSRF and user/account data. **Only** static assets in
the `/assets/admin/` subdirectory receive
`Cache-Control: public, max-age=31536000, immutable` via that directory's
owned `.htaccess`. The deployment script copies it explicitly; it never
overwrites the document-root cPanel-managed `.htaccess`, `php.ini`, or
`.user.ini`. If LiteSpeed/Apache/header modules or a proxy override the
headers, inspect the actual response; do not assume browser caching works.
The `?v=<hash>` URL changes whenever the matching artifact content changes.

After building assets on a trusted Mac, commit generated
`public/assets/admin/{app.css,app.js,icons.svg,theme-init.js,.htaccess}`
as appropriate; use the normal cPanel manual Deploy HEAD Commit flow.
Verify through browser DevTools: icon sprite loads from same-origin, no blank
icons during navigation, 200 on initial asset fetches and memory/disk-cache
on repeat visits, no caching of authenticated HTML, and no CSP violations.
