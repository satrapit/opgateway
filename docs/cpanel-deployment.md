# Deployment and diagnostics

OPGateway runs on cPanel shared hosting. Private checkout:
`/home/arsamnet/repositories/opgateway`; public Document Root:
`/home/arsamnet/opgateway.arsamnet.com`.

`.env` (private) contains **only** `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, and `DB_PASSWORD`. The protected
`storage/app-secret.key` must be created only once, with permissions
0600, outside public Document Root. Back it up separately; losing it
breaks decryption of existing SMTP and owner provider credentials.
Actual PHP HTTPS `SERVER_NAME` must be the canonical gateway hostname,
matching `HTTP_HOST`.

The `.cpanel.yml` allowlist copies only the public entrypoint and the
reviewed precompiled admin assets. Never place private source, Composer
vendor, key, `.env`, templates or logs in Document Root. No server-side
Composer, npm, database migration, cron or GitHub workflow runs.

## Production pilot release boundary

`v0.1.0-alpha.4` is the pilot snapshot at commit
`ffa90e0d40bb7ece1867f935bde40f0ca88b3869`: six migrations and 13
final tables. Subsequent Google OIDC development lives separately and must
NOT be deployed during this pilot.

## Controlled rollout

1. **Do not deploy the draft PR yet.** On a disposable database, validate
   migrations 004, 005 and 006, admin login, SMTP, email verification,
   password recovery, client/IP approval,
   API issuance and safe read-only Openprovider requests.
2. Take and verify a production database backup, then review the existing
   three migration records.
3. Coordinate a maintenance window to apply the additive
   `database/sql/upgrade_202611100001.sql` (004), then
   `database/sql/upgrade_202611100002.sql` (005), then
   `database/sql/upgrade_202611100003.sql` (006).
   Deploying new PHP code before all three are completed returns 503 on admin/account.
4. Verify all six migration records and 13 final tables
   (with `settings` and `password_reset_tokens`, without `smtp_settings`)
   before enabling
   real traffic. Do not re-import the full initial schema.
5. On trusted local Mac run PHP lint, PHPUnit, smoke, Composer validation,
   frontend lint/build and browser tests. Commit reviewed vendor and static
   assets; do not include development dependencies in the server checkout.
6. Deploy reviewed `main` via cPanel Git Version Control manually.
   Validate `/health`, admin/owner HTTPS, login security and public
   resource denial. Reverse with a reviewed revert commit, not destructive
   database/working-tree reset.

The application emits no-store, frame-denial, nosniff, CSP and
same-origin referrer headers. Preserve the cPanel-managed .htaccess,
php.ini and .user.ini. The authenticated browser pages have only a
single TailAdmin/Twig renderer; no environment toggle or fallback.

## Diagnostics

Private diagnostics: `storage/logs/opgateway.jsonl`; use
`X-Request-ID` to correlate server events. Do not log secrets.
`admin.database_connection_failed` indicates MySQL connection issues;
`admin.schema_incomplete` / `admin.schema_inspection_failed` indicates
migration problems; `admin.secret_unavailable` means a missing or
incorrectly permissioned private application key.

For hosted MySQL `DB_HOST=db`; for local MySQL use the actual endpoint,
often `127.0.0.1` instead of the Unix socket selected by `localhost`.

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



## Unreleased account and provider integration

SMTP Settings are seven key/value rows in shared MySQL `settings` and
are managed by the existing admin role.
No `*_ENABLED` switches or extra administration roles are required.
Never deploy the new source against an un-migrated production database.
See [onboarding](gateway-onboarding.md).
