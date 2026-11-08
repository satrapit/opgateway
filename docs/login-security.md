# Login security and migration readiness

## Implemented internal components

- GuardedLoginService reserves limits before checking credentials.
- PdoLoginAttemptLimiter uses MySQL counters for a 15-minute window with
  conservative per-IP and per-identity limits.
- Bucket keys are HMAC-SHA-256 digests based on a private, random
  application key (at least 32 bytes); never commit the key.
- PdoSecurityEventRecorder writes only a restricted event code, random
  request correlation ID and timestamp, with no personal data or secrets.
- Security components fail closed on database errors.

## Installation state

The original seven-table production database and migration 001 are already
confirmed. **This commit does not import, alter, or recreate any table.**
The presence of migration 002 (email verification) has NOT been confirmed.

There are now three ordered immutable PHP migration definitions.
A reviewed version-003 SQL artifact is available at
`database/sql/202611070003_login_security.sql`, which creates only
`login_rate_limits` and `security_events`.

**Do not import migration 003 unless 002 is already installed and completed.**
Before any import: inspect the target schema and existing ledger, take a
backup, and verify the tables are absent. Stop on any error. Because MySQL
DDL implicitly commits, never blindly rerun an import. Future deployment
instructions must respect the existing manually imported schema.

Migration 003's SHA-256 checksum is
`a2932ddac794ac00993a4077dd67d7ca4dd94ec9df971d81cf15b4518342d070`, corresponding exactly to the ordered DDL statements
in `CreateLoginSecurityTables::statements()`.

## Still required before public login

1. Verify the manually provisioned administrator and restrict login.
2. Trustworthy HTTPS/session and CSRF integration at the real HTTP edge.
3. Application-specific rate-limit key loaded from private environment.
4. Integration tests for concurrent rate-limit updates and audit persistence.
5. Bounded data retention / pruning strategy for old counters and events.
6. Complete browser flow and generic non-enumerating error responses.

No public endpoint is enabled by this change. Code deployment needs no
server shell, Docker, Composer invocation, GitHub workflow, or Mac PHP CLI.
