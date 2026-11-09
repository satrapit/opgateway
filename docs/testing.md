# Testing and validation

Run locally on PHP 8.3:

```bash
composer install
php scripts/lint.php
composer test
./vendor/bin/phpunit --display-phpunit-notices
php scripts/smoke.php
composer validate --strict --no-check-publish
```

The latest user-verified baseline before the Twig/TailAdmin admin UI refactor was **131 PHPUnit tests / 609 assertions, zero notices**, and **10/10 smoke checks**. A new baseline must be recorded only after running the expanded suite; do not infer test success from code review.

Smoke tests confirm `/health`, deny-by-default `/v1`, IP helper behavior and safe metadata; they **do not** prove live authentication, schema compatibility, ownership enforcement or upstream connectivity. Test administrator login/logout and the separately disabled owner account/credential routes over HTTPS in a real browser; unit tests do not substitute for real MySQL authorization or key handling.

## Optional disposable MySQL integration test

`tests-integration/MigrationRunnerIntegrationTest.php` is **excluded** from the normal PHPUnit suite because it modifies the target database. It uses the fixed empty schema `opgateway_integration_test` on `127.0.0.1:13306`. **Never point it at the local working database or the production DB.**

On a Mac with Docker, create an isolated MySQL 8.0 instance:

```bash
export OPGATEWAY_TEST_MYSQL_PASSWORD="$(openssl rand -hex 24)"
docker run -d --name opgateway-mysql-it \
  -p 127.0.0.1:13306:3306 \
  -e MYSQL_RANDOM_ROOT_PASSWORD=yes \
  -e MYSQL_DATABASE=opgateway_integration_test \
  -e MYSQL_USER=opgateway_test \
  -e MYSQL_PASSWORD="$OPGATEWAY_TEST_MYSQL_PASSWORD" mysql:8.0

# After the test MySQL server is accepting connections:
./vendor/bin/phpunit tests-integration/MigrationRunnerIntegrationTest.php

docker rm -f opgateway-mysql-it
unset OPGATEWAY_TEST_MYSQL_PASSWORD
```

Keep all test credentials disposable; never run `set -x` when passing secrets. No GitHub workflow is used for tests.

## Twig/TailAdmin test gates

`AdminViewMigrationTest` tests the existing adapter's role/session boundary with an injected renderer, even when Twig is not installed. `TwigAdminPageRendererTest` checks a fail-closed missing-dependency path or, once Twig is installed, real template rendering and escaping. This is not a substitute for a real Twig test run. After the local lock/build step, use `npm run lint && npm run build` in `frontend/`, PHP lint/PHPUnit/smoke tests at repository root, and manually test 320/375/390/768/1024/1440px, dark/light/system, Keyboard Tab/Escape, denied IP/role/CSRF, CSP console warnings and zero HTTP requests to external CDNs. Do not report these as passing until completed.

## Integrated UI branch — rebuild required

Commit `e5fcb72` on the **previous parallel** branch passed 136 PHPUnit tests, 640 assertions, 10/10 smoke checks and Webpack build. These results do not validate this consolidated renderer and frontend.

Run locally:

```bash
composer update twig/twig -W
composer validate --strict --no-check-publish
php scripts/lint.php
./vendor/bin/phpunit --display-phpunit-notices
php scripts/smoke.php
cd frontend
npm install
npm run lint
npm run build
test -s ../public/assets/admin/app.css
test -s ../public/assets/admin/app.js
test -s ../public/assets/admin/theme-init.js
```

Commit fresh `composer.lock`, `frontend/package-lock.json` and three matching `/public/assets/admin/` files. Then verify local HTTPS/IP/CSRF/role boundaries, real login and read-only dashboard, both mobile and desktop widths (320/375/390/768/1024/1440), keyboard focus/drawer behavior, dark/light/system preferences including login, CSP and owner portal regression.

## Reproducible stylesheet whitespace

`npm run build` now runs the Webpack production build followed by
`frontend/scripts/normalize-css.mjs`. This only removes trailing empty
lines and appends exactly one POSIX newline to the generated
`public/assets/admin/app.css`; it does not modify the source stylesheet
or change CSS selectors. The generated files remain version-controlled.
`git diff --check` should remain clean after repeat builds.

## Local Lucide license bundling (November 2026)

After adding Lucide, Webpack's default Terser minimizer emitted
`public/assets/admin/app.js.LICENSE.txt` while the cPanel deployment
allowlisted only three runtime assets. The build explicitly configures
`TerserPlugin({ extractComments: false, terserOptions: { format:
{ comments: 'some' } } })` to **retain relevant copyright/license
comments inside the versioned `app.js`**. The full unmodified Lucide/Feather
license is retained in `frontend/LUCIDE-LICENSE`, alongside the TailAdmin
license. The production build must not create a sidecar file. Check with
`test ! -e public/assets/admin/app.js.LICENSE.txt` and inspect the
minified JS for copyright/license notices before staging.

For the default-off admin routes, a body-less POST without
`Content-Length` deliberately returns 413 from
`BoundedRequestBody`; it does not test the later mutation feature flag.
Use a non-secret, form-encoded stub body to confirm `/admin/review`
and `/admin/provision` yield 404 while
`ADMIN_ACTIONS_ENABLED=false`. Never enable mutation flags to run
this negative test.

## Record-dialog UX slice — verification boundaries (2026-11-10)

The developer verified **148 PHPUnit tests / 792 assertions**, **10/10**
smoke checks, no NPM audit vulnerabilities, and all **7/7** responsive
browser checks for commit `20fb975` (the independent-page baseline).
The new progressive-enhancement dialog slice was committed **after**
that verification. These baseline results do not prove its success.

The current dialog slice must be verified with:

1. `php scripts/lint.php`; `./vendor/bin/phpunit --display-phpunit-notices
   --display-warnings`; `php scripts/smoke.php`.
2. `cd frontend && npm ci && npm run lint && npm run build`,
   `git diff --check` and no license sidecar or remote runtime asset.
3. While `ADMIN_ACTIONS_ENABLED=false`, authenticate an admin on a
   local-only database and test User/Owner/Client/Pending IP/Approved IP
   record-details dialogs, Escape, close buttons, focus restoration,
   320/390/768/1024/1440px, dark mode, and JS/CSP console errors.
   No mutation forms should be present in this read-only state.
4. The dialog-enhanced `create_client`, `request_ip`, `disable_client`
   and `revoke_ip` interaction can only be browser-verified in an
   **isolated local test database**, after a separate explicit review
   of activating `ADMIN_ACTIONS_ENABLED=true`; never change production
   flags just to exercise a UI modal. Confirm that cancel/Escape submits
   nothing, that real form fields remain identical, and that no-JS
   fallbacks still work. Destructive backend checks remain authoritative.
5. Server POST responses retain their existing redirect/status behavior;
   **do not report toast/success/error-message functionality as complete**.
   True operation result notices require a separately reviewed
   server-side response design.

Do not reset or pop the previously preserved local UI stash over this
branch. The branch stays Draft until the above checks and authorized
operations are signed off.
