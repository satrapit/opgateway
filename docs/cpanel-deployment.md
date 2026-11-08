# Deployment and operations

## cPanel paths

- Private Git checkout: `/home/arsamnet/repositories/opgateway`.
- Document Root: `/home/arsamnet/opgateway.arsamnet.com`.
- Secrets: private checkout `.env`, outside Document Root.
- Diagnostics: private `storage/logs/opgateway.jsonl`, writable by PHP, never public.

The reviewed UI branch's versioned `.cpanel.yml` copies **only `public/index.php` and the allowlisted compiled admin assets** (`public/assets/admin.css`, `public/assets/admin.js`, `public/assets/theme-init.js`). Missing assets or public symlink destinations make deployment fail rather than silently serving incomplete UI. It checks the destination exists, `.htaccess` exists and the target index is not a symlink. It **does not delete or clean up any deployed files**. The cPanel-managed `.htaccess`, `php.ini` and `.user.ini` are not overwritten. Old standalone files, if any, must be audited and removed deliberately via File Manager, not by future deployment scripts.

## Manual deployment

1. On a trusted local Mac with PHP 8.3/Composer, run `composer update twig/twig --with-dependencies` **once** to update and commit `composer.lock`. Then run `composer install --no-dev --prefer-dist --optimize-autoloader` for a reviewed private production vendor tree. Never copy vendor to Document Root.
2. Under `frontend/`, run `npm install` **once** to create and commit `package-lock.json`. Then `npm ci && npm run check && npm run build`, verifying all three real files exist under `public/assets/`. Do not run NPM on cPanel.
3. Locally run `php scripts/lint.php`, `composer test`, `./vendor/bin/phpunit --display-phpunit-notices`, `php scripts/smoke.php` and `composer validate --strict --no-check-publish`.
4. Review compiled bundles for unexpected imports, runtime CDNs, secrets and sourcemaps; run responsive/mobile/keyboard/theme browser checks. Deploy private PHP vendor dependencies **to the private checkout path** through an approved cPanel File Manager/transfer workflow. Confirm Twig classes load and assets are reachable before setting `ADMIN_TWIG_UI_ENABLED=true` in the private `.env`. Keep this flag false on existing installations until all checks pass.
5. Commit reviewed `composer.lock`, `frontend/package-lock.json`, and three built assets; push approved changes to `main`. Never commit real `.env`.
6. cPanel → Git Version Control → **Update from Remote**, verify commit → **Deploy HEAD Commit**.
7. Confirm `https://opgateway.arsamnet.com/health` returns HTTP 200 and `{"status":"ok"}`.
8. Confirm `/v1/domains` remains HTTP 503 with `gateway_not_configured`.
9. Check private URLs (`/.env`, `/src/autoload.php`, `/composer.json`, `/.git/config`, `/storage/`) do not return HTTP 200.
10. Confirm deployed rewrite rules and existing cPanel PHP handler remain intact.
11. Test admin browser login/dashboard/logout from the allowed IP only when `ADMIN_UI_ENABLED=true`.

No server-side shell, automated migration, Docker, cron, GitHub Actions or CI workflow is required. Roll back by a reviewed revert commit and the same manual deployment, not by resetting the deployed Git checkout.

## PHP and .htaccess

Set production `display_errors=Off`, `log_errors=On` and `APP_DEBUG=false`. Keep cPanel-generated PHP sections untouched. The application emits no-store, frame-denial, nosniff, CSP and same-origin referrer headers. Admin pages use CSP `script-src 'self'; style-src 'self'` for built local assets without `unsafe-eval`; the unchanged owner portal retains its existing inline-style allowance until a separately reviewed owner UI migration. Admin authentication relies on session CSRF, not on the browser `Origin` header.

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
