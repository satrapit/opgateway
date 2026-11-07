# cPanel Git Version Control — OPGateway

## This deployment's fixed paths

- Private source repository: `/home/arsamnet/repositories/opgateway`.
- Site document root: `/home/arsamnet/opgateway.arsamnet.com`.
- GitHub repository: `satrapit/opgateway`.
- Server-side SSH configuration is maintained separately; do not change its keys or aliases as part of application releases.

The web entrypoint `public/index.php` selects the private source repository when it is running outside the local source checkout. This host-specific path must be changed explicitly for other installations; never let the HTTP request choose a source root.

## Security boundary

The checked-in `.cpanel.yml` copies **only** `public/index.php` and `public/.htaccess` to the webroot. It refuses deployment if the destination directory or files are missing. It does not mirror or delete existing files, so remove any previously exposed sensitive files from the document root through File Manager **after verifying they are not required**, and never place credentials in that directory.

The `.env` file belongs in `/home/arsamnet/repositories/opgateway/.env` (outside the webroot). Create it manually from `.env.example` using cPanel File Manager and set restrictive file permissions. Never commit `.env`.

The cPanel account's private Git deploy key must never be placed in either the repository or the webroot. Other Git repositories' SSH configuration must remain unchanged.

## Manual pull deployment — no server terminal

1. On the Mac, run the local syntax check and smoke tests before committing any changes. Run Composer validation and PHPUnit after installing dependencies locally.
2. Push a reviewed commit to `main` on GitHub and create the corresponding Git tag locally after tests pass.
3. In cPanel → Git Version Control → OPGateway → Manage → Pull or Deploy, click **Update from Remote**. Confirm the expected commit is shown.
4. Click **Deploy HEAD Commit** to execute the root `.cpanel.yml` tasks. The process copies the two approved public files.
5. From a browser, check `https://opgateway.arsamnet.com/health`. It returns `{"status":"ok"}` only if PHP can read the private bootstrap and `.env`. A missing configuration yields 503. Check `/v1/domains` responds 503, **not** an upstream response.
6. Check from the browser that private files are not served: `/.env`, `/composer.json`, `/.git/config`, `/src/autoload.php`, and `/storage/` must not be accessible. Expected 404/403; a 200 is a deployment blocker.
7. For rollback, select the previous tested commit in GitHub (via a new non-destructive revert commit), run Update from Remote and Deploy HEAD Commit again. Do not hard reset the cPanel-managed repository or rewrite remote history.

The Git Version Control interface requires a root-level checked-in `.cpanel.yml`, a branch, and a clean working tree. The Update and Deploy buttons are separate operations. No GitHub Actions or automated deploy pipeline is used.

## Current release constraints

Foundation phase: all `/v1` routes are disabled. The gateway has no account system, provider integration or database migrations. Do not send live client traffic to it. The production installation has no Composer or npm requirement **at this phase** because the custom minimal autoloader loads only the application's own classes. Future production dependencies require a reviewed local build and safe deployment package, not an assumed server-side Composer invocation.

References:
- https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/
- https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-deployment/
