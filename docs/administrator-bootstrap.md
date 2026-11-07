# First administrator bootstrap (internal foundation)

This release adds two internal PHP services:
- `BootstrapSecret`: checks a hex-encoded 256-bit out-of-band secret
  against a SHA-256 digest using `hash_equals`.
- `AdministratorBootstrap`: creates the first admin user, admin role and
  membership together using an InnoDB transaction.

**No public installer, command, script or HTTP route invokes these services.**
No administrator is created by deployment, and no password or setup token is
hardcoded, seeded, committed or logged.

## Irreversible setup guard

The bootstrap service refuses to run unless `users`, `roles` and
`user_roles` are all empty. Even one existing pending user causes denial.
It uses a MySQL advisory lock to serialize installer calls, then a transaction
for the user/role inserts. A future administrator UI must also enforce an
independently configured, one-time installation lock that remains closed
after setup. Never expose this class through a raw POST endpoint.

Bootstrap uses an explicitly privileged, out-of-band exception: the first
administrator starts active/approved and with a verification timestamp.
Future normal users must complete real email verification and admin approval.
Only a trusted operator installer with HTTPS, independent setup access
control and a verified operator should invoke this class.

## Requirements before enabling installation

1. Introduce a protected, audited operator-only cPanel-compatible setup
   interface with HTTPS-only access, short-lived signed CSRF, rate limiting
   and a setup-enabled flag that defaults off.
2. Require the operator to configure a strong, random secret outside Git,
   and store **only its SHA-256 digest** in the private configuration.
   The full plaintext token must never appear in logs or URL parameters.
3. Check initial table/schema integrity and separately confirm no users
   or roles already exist.
4. Include explicit one-time consent and a durable install-lock marker.
   Rotate and remove the setup secret immediately after use.
5. Test against disposable MySQL with duplicates, concurrent attempts,
   rollback on failure, empty/nonempty databases and disabled installer.
6. Preserve database backup and a documented manual recovery path.

No changes are required to the already confirmed seven-table schema.
There is **no SQL to import for this code-only update**. The Mac is used
for Git commands only, while the shared server needs no Terminal or Docker.

## Current operational state

Production `/health` stays unchanged and the public API still denies
`/v1` requests. No registration, login, account approval or bootstrap
route is exposed; the admin remains unprovisioned until the operator
installer is explicitly built, tested and enabled.
