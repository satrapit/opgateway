# Operator-only initial administrator installation

A real but **default-disabled** `/setup/administrator` handler now exists.
It is not a registration or public onboarding endpoint.

## Security boundaries

- Route is inaccessible (404) unless private `SETUP_ENABLED=true`.
- Requires server-confirmed `HTTPS=on`; never trusts forwarding headers.
- Requires exact source `REMOTE_ADDR` matched against `SETUP_ALLOWED_IP`.
- Requires `APP_URL` to be a canonical HTTPS origin, strict same-origin POST,
  secure SameSite session, session-backed CSRF and small bounded form data.
- Requires a random 256-bit hex setup token whose SHA-256 digest of the
  **decoded 32 bytes** is stored privately as `SETUP_TOKEN_HASH`.
- The underlying transactional `AdministratorBootstrap` refuses installation
  if ANY users, roles or user-role memberships already exist.
- No password, token, private configuration or request body is logged.
- The route does not enable general admin login or Openprovider access.

## Private configuration (disabled by default)

Only when ready to perform a reviewed, brief operator setup window, configure
the following in `/home/arsamnet/repositories/opgateway/.env`:

```
SETUP_ENABLED=false
SETUP_ALLOWED_IP=
SETUP_TOKEN_HASH=
```

The initial configuration MUST use `false`. Before deliberate activation,
take a database backup, ensure `users`, `roles` and `user_roles` are
empty, verify the actual cPanel TLS metadata and operator's direct source
IP. Never place the setup token, digest or administrator password in Git,
in a screenshot, or in support chat.

A cryptographically secure 32-byte random token is represented as 64 lowercase
hex characters. Compute SHA-256 from the raw 32 bytes, **not** the printable
hex string; enter only the lowercase digest in private `.env`. Keep the token
in a private password manager for the one setup form submission.

When separately verified and authorized, temporarily set
`SETUP_ENABLED=true`, navigate from the allowed IP to
`https://opgateway.arsamnet.com/setup/administrator`, submit one setup
request, then immediately set `SETUP_ENABLED=false` and DELETE the private
`SETUP_TOKEN_HASH` value. A second bootstrap cannot pass the database
empty-state checks even if the config toggle is accidentally left enabled.

**Do not enable the route yet without live testing of the HTTPS and IP
boundaries.** The setup form is usable only if the cPanel configuration reports
a trusted direct HTTPS connection. Do not weaken the check to trust user
provided `X-Forwarded-Proto` or `X-Forwarded-For`.

## Operational steps

- Three migrations (001, 002, 003) already imported: no new SQL.
- Existing database must not be recreated, re-imported or manually changed.
- MacBook: use Git only, pull the new commit from main.
- cPanel: Update from Remote then Deploy HEAD Commit; existing .cpanel.yml
  copies ONLY public/index.php. .htaccess stays under cPanel's control.
- After deployment while setup is disabled, /setup/administrator stays 404,
  /health stays 200, /v1 stays 503.
- The admin login/dashboard routes remain unavailable; enable only after
  their security dependencies and integration tests are complete.

## Caveat

The installer has a default-deny boundary and tests for its early rejection
cases, but an actual hosted MySQL/TLS/session integration test is still needed.
Treat this as an explicit opt-in installation feature, not an automatic or
already-proven production setup.
