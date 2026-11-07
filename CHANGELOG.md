# Changelog

## [Unreleased]

### Deployment improvements (candidate for v0.1.0-alpha.2)

- Add explicit cPanel deployment manifest for the arsamnet source/webroot split.
- Adapt HTTP bootstrap for separate deployed Document Root and private source repository.
- Ignore PHPUnit's result cache and remove redundant Composer version field.
- Document manual update/deployment and security checks.

This change has not been verified on the target cPanel instance. No new release tag should be created until local tests and initial cPanel deployment checks pass.

## [0.1.0-alpha.1] - 2026-11-07

- Initial fail-closed PHP 8.3 foundation with local test scaffolding.
- Baseline architecture, security and API-compatibility documentation.
- Documented manual Git Version Control update process for cPanel.

**Not production-ready:** no upstream proxy, authentication or tenant isolation.
