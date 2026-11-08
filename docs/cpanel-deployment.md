# cPanel Git Version Control — OPGateway

## Paths

- Private repository: `/home/arsamnet/repositories/opgateway`.
- Document Root: `/home/arsamnet/opgateway.arsamnet.com`.
- Remote repository: `satrapit/opgateway`.
- The existing SSH key configuration is managed separately. **Do not modify it as part of an application deployment**; the currently selected key is required for this repository.

## Ownership of configuration files

The cPanel MultiPHP INI Editor manages `php.ini`, `.user.ini`, and generated sections of the **deployed** `.htaccess`. Those server-side files must not be overwritten by Git deployments. The source `public/.htaccess` remains a reference template and is **not copied** by `.cpanel.yml`.

The deployed `.htaccess` must retain:
- `Options -Indexes` and `DirectoryIndex index.php`.
- Rewrite of virtual paths to `index.php` when the requested file does not exist.
- Security response headers such as `X-Content-Type-Options: nosniff`.
- cPanel-generated PHP directives and PHP handler, as applicable to the selected runtime.

Make a backup of the deployed `.htaccess` in private storage before editing it. Do not copy cPanel-generated PHP directives to the public Git repository. Check after every deployment that rewrites still work.

The PHP error log must be placed outside Document Root (for example in private `storage/`) with a writable and protected target; never expose PHP errors or log contents to clients. In production, set `display_errors=Off`, `log_errors=On`, and `APP_DEBUG=false`. The effective PHP settings are managed through cPanel, not by `.cpanel.yml`.

## Fail-closed deployment

The version-controlled `.cpanel.yml` copies **only** `public/index.php` into Document Root. Before copying, it checks that the target directory and its existing `.htaccess` are present, and that the destination index is not a symlink. It does not copy `.env`, `src`, `vendor`, `storage`, `tests`, `.git` or `composer.lock`. As a one-time security cleanup, it explicitly deletes only two obsolete temporary test entrypoints (`db-connection-test.php`, `mysql-direct-test.php`) in Document Root; all other destination files are preserved.

The runtime bootstrap loads source from `/home/arsamnet/repositories/opgateway`. Production database hostname is `db` on this provider, not `localhost`. The private `.env` must exist in that repository root (outside the webroot); it must never be committed or published. The initial alpha release requires no server-side Composer command.

## Manual update through cPanel

1. On the Mac, run `php scripts/lint.php`, `php scripts/smoke.php`, `composer validate --strict --no-check-publish`, and `composer test`.
2. Push a reviewed commit to `main`. Create an annotated release tag only after relevant validation and deployment checks.
3. In cPanel → Git Version Control → OPGateway → Pull or Deploy, run **Update from Remote** and verify the latest commit.
4. Run **Deploy HEAD Commit**. Check the cPanel deployment log.
5. Verify `https://opgateway.arsamnet.com/health` returns `{"status":"ok"}` and `/v1/domains` returns HTTP 503 with `{"error":"gateway_not_configured"}`.
6. Check that `/.env`, `/composer.json`, `/.git/config`, `/src/autoload.php` and `/storage/` cannot be fetched publicly. Any HTTP 200 on a private file is a deployment blocker.
7. Confirm the deployed `.htaccess` still contains its cPanel-generated sections and the required rewrite rules.
8. Confirm both old database-test URLs return 404. Remove any separate private diagnostic config file manually via File Manager after confirming it is not used elsewhere.

For rollback, use a reviewed non-destructive revert commit followed by manual Update from Remote / Deploy HEAD Commit. Do not rewrite Git history or reset the cPanel-managed checkout.

## Known limitations

This is only a foundation. The Openprovider API is still blocked, and the platform has no users, database migrations, tenant isolation or provider integration. Never route real API clients to this version.

References:
- https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/
- https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-deployment/
