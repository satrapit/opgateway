# cPanel Git Version Control — manual source updates

> This release is a non-production foundation. All `/v1` routes return 503; do not route real API clients to it.

## Remote repository

- GitHub remote: `git@github.com:satrapit/opgateway.git` (private).
- Default branch: `main`; prerelease: `v0.1.0-alpha.1`.
- cPanel **Git Version Control → Update from Remote** refreshes the checked-out source, not a separate document root.
- **Deploy HEAD Commit** requires `.cpanel.yml`, which this project deliberately omits. Do not use that button.

## Git-managed installation layout

1. Configure a dedicated subdomain with its document root pointing at `<repository-dir>/public` (not the repository root). Confirm the host allows a custom document root before relying on this layout.
2. Configure a **read-only GitHub deploy key** for the private repository using cPanel SSH access or the provider's support. Do not commit the key. If the account has no SSH/Terminal access, private GitHub cloning may require the hosting provider's assistance.
3. In cPanel → Git Version Control choose *Clone a Repository*, URL `git@github.com:satrapit/opgateway.git`, and a private repository directory outside any web-accessible document root.
4. In Git Version Control → Manage → Pull or Deploy select **Update from Remote**. It performs a fast-forward pull on the checked-out branch. Avoid modifying tracked files on the server.
5. `.env` is intentionally untracked and must be provisioned separately at the project root, outside `public/`; it is not replaced by Git updates.
6. When later dependencies are introduced, cPanel Git does not run Composer automatically. Plan a local build and a manual `vendor/` transfer or a separately reviewed release process; never assume that a Git pull installs dependencies.
7. Verify HTTP endpoints, permissions, and access controls after each update. Maintain a tested rollback plan and review database migration compatibility first.

## Release checks

- `GET /health` returns a non-sensitive `{ "status": "ok" }` when `.env` exists and parses.
- `/v1` and `/v1/*` return HTTP 503 in this prerelease, including if `GATEWAY_PROXY_ENABLED=true`.
- `src/`, `.env`, `vendor/`, `storage/`, and Git metadata must not be reachable over HTTP.

## Production readiness blockers

Before any production traffic: implement credentials, admin approval, exact client IP allowlists, resource ownership, migrations, verified upstream API contract, rate limiting, safe logging, token management, and comprehensive tests.

References: https://docs.cpanel.net/cpanel/files/git-version-control/ and https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-access-to-private-repositories/.
