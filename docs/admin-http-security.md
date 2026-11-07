# Administrative HTTP security foundation

This stage introduces two **internal** services: `AdminRequestPolicy` and
`PdoAdministratorAuthorization`. Neither is connected to `public/index.php`
or any public endpoint. The existing public `/v1` routes continue to fail
closed. No database migration, cPanel configuration or installation action
is required.

## Admission order for future state-changing requests

1. Verify the connection is HTTPS at the trusted web server; never trust a
   client-supplied `X-Forwarded-Proto` without an explicit trusted proxy.
2. Load secure browser session state and resolve the authenticated user.
3. Revalidate user status, verification, approval and membership in the
   administrator role **from the database**.
4. Allow POST only. Match the supplied `Origin` byte-for-byte to the
   configured HTTPS canonical origin. Missing/foreign Origin fails closed.
5. Reject missing, invalid or oversized Content-Length; cap JSON request
   bodies at 16 KiB. Reject unsupported Content-Type.
6. Validate a server-issued session CSRF token using constant-time comparison.
7. Only then parse, validate and dispatch a specifically allowlisted action.
   Authorization must also be checked inside the domain service/SQL.
8. Record sanitized audit metadata and do not expose exception messages.

The policy accepts booleans such as `authenticated`, `isAdmin` and
`csrfValid` as **trusted internal inputs**, never as HTTP parameters.
An HTTP controller that maps request input onto those booleans would be a
critical authorization bypass.

## Incomplete prerequisites

No live admin controller, login page, session-bound action handler, initial
administrator setup UI, user list, CSRF integration, body-stream validation,
audited action dispatcher, or operation permission system is implemented yet.

Request body size must additionally be enforced during streaming/reading;
Content-Length alone is insufficient for chunked requests or forged headers.
An eventual controller must read at most 16 KiB + 1 byte and reject excess,
including invalid/multiple Content-Length header values.

Password login must use the guarded, rate-limited orchestration, not a direct
call to `LoginAuthenticator`. A complete UI cannot be enabled until
administrative bootstrap and stateful session flow have been tested.

## Hosting

Use Git operations on the Mac; standard cPanel and phpMyAdmin on the server.
No hosted shell, Docker, Composer or background workers. No GitHub workflows.
No change to the confirmed existing database.
