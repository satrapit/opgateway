# Changelog

## [Unreleased]

- Prevent cPanel deployments from overwriting the server-managed `.htaccess`.
- Require the deployed `.htaccess` to exist before deploying the public entrypoint.
- Clarify ownership and protection of cPanel PHP INI settings and error logs.

## [0.1.0-alpha.2] - 2026-11-07

- Add a manual cPanel deployment manifest for the split repository and public Document Root.
- Adapt HTTP bootstrap for separate deployed Document Root and private source repository.
- Ignore PHPUnit result cache and remove the redundant Composer version field.
- Add a Composer lockfile for repeatable dependency installation.
- Verify application bootstrap and fail-closed upstream routes on the target shared hosting.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation with local test scaffolding.
- Baseline architecture, security and API-compatibility documentation.

**Not production-ready:** no upstream proxy, authentication or tenant isolation.
