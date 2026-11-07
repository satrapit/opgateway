# Login protection foundation — not exposed publicly

## Internal safeguards

`GuardedLoginService` coordinates request reservation, password verification
and a restricted security event. An HTTP controller must NEVER invoke
`LoginAuthenticator` directly.

`PdoLoginAttemptLimiter` uses atomic MySQL upserts to reserve fixed-window
counters for both the transport-verified IP and normalized identity. It
rejects malformed addresses, uses HMAC-SHA-256 bucket identifiers and
requires an application-specific high-entropy key supplied privately (not
in the source tree). Current provisional limits are 30 attempts/IP and
8 attempts/identity in a 15-minute window; these require abuse testing.

`PdoSecurityEventRecorder` writes only preapproved event codes, a
server-generated correlation ID and timestamp. It never persists passwords,
bearer credentials, emails, raw addresses, request bodies or headers.

Database failures throw and must be handled at the HTTP edge as generic
service errors. Do not bypass the limiter or event recorder on failure.

## cPanel database installation (deferred)

These internal classes require two **additional** tables:
`login_rate_limits` and `security_events`. A reviewed SQL draft is in
`database/sql/202611070003_login_security.sql`; it is not automatically
installed through Git deployment. The existing seven-table database and
first Migration ledger row are already verified and must not be recreated.

**Do not import the draft SQL yet.** It is intentionally independent of the
migration ledger pending alignment with the canonical migration catalog.
A consistent versioned migration bundle and operational preflight will be
prepared before activating the login controller.

## Still needed before public login

- HTTPS-only controller, strict request/body limits and same-origin checks.
- Server-managed session initialization and CSRF verification.
- Admin bootstrap that cannot be repeated after setup.
- Safe password reset, account recovery and session revocation.
- Database tests for concurrent rate-limit claims and audit delivery.
- Persistent cleanup strategy for old counters/events (without cron).
- Email ownership verification and proper administrator account approval.

Production routes remain fail-closed and no live login request can reach
these internal classes until all prerequisites pass.
