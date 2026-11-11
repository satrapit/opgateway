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

## Single-domain Google release — rollback prerequisite

Only `https://opgateway.arsamnet.com` is used for deployed testing and
production. No separate staging hostname is required or assumed. All
unreleased work and automated tests happen locally on Mac first.

The original `v0.1.0-alpha.4` is **not** a safe code-only rollback after
migration 007: its strict schema preflight rejects the extra migration.
This compatibility-only patch should be reviewed, tested, merged into main
and tagged as the pre-Google fallback (proposed `v0.1.0-alpha.4.1`).
It accepts the exact completed migration 007 checksum in addition to its
six known migrations, but does not implement or configure Google sign-in.

Required deployment order on the single production hostname:

1. Back up the database and `storage/app-secret.key`. Verify backup.
2. Deploy and verify this compatibility-only main version while the DB
   still contains six completed migrations.
3. Review and apply the additive migration 007 ONCE during maintenance.
   The compatibility version continues serving Account and Admin pages.
4. After local PHP/MySQL/browser review, merge Google feature to main,
   deploy Google code via the existing cPanel allowlist to the same domain,
   and set Google OAuth Web callback URI exactly to
   `https://opgateway.arsamnet.com/account/google/callback`.
5. If the Google version fails, restore the compatibility-only commit
   (alpha.4.1), *not* the original alpha.4, keeping migration 007 and
   all existing database data in place. Correct any partial deploy first.

No Google feature or migration 007 may be deployed during the original
alpha.4 production pilot. Changing the shared host's private checkout
affects the live domain; do not perform a feature-branch cPanel deploy.

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
