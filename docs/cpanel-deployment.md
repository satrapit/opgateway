# Deployment and operations

## cPanel paths

- Private Git checkout: `/home/arsamnet/repositories/opgateway`.
- Document Root: `/home/arsamnet/opgateway.arsamnet.com`.
- Secrets: private checkout `.env`, outside Document Root.
- Diagnostics: private `storage/logs/opgateway.jsonl`, writable by PHP, never public.

The versioned `.cpanel.yml` **only copies `public/index.php`**. It checks the destination exists, `.htaccess` exists and the target index is not a symlink. It **does not delete or clean up any deployed files**. The cPanel-managed `.htaccess`, `php.ini` and `.user.ini` are not overwritten. Old standalone files, if any, must be audited and removed deliberately via File Manager, not by future deployment scripts.

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
