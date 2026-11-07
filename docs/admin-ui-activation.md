# Administrator sign-in activation (opt-in, not yet validated on hosting)

This stage wires the existing browser login/logout/dashboard adapter to
`public/index.php` behind a **separate, disabled-by-default** switch.

## Boundary controls

- `ADMIN_UI_ENABLED=false` (default) makes all `/admin` routes 404.
- Requests require trusted `HTTPS=on` and exact `REMOTE_ADDR` equal to
  the explicitly configured `ADMIN_UI_ALLOWED_IP`. Forwarded headers
  are NOT trusted. This deliberately supports one operator IP initially.
- A strict canonical `APP_URL` and independently generated 256-bit HMAC
  key (`LOGIN_RATE_LIMIT_KEY`, lowercase 64-character hex) are mandatory.
- Checks all three versioned migration records and checksums before starting
  a privileged browser session. Unknown or incomplete history fails closed.
- Enforces secure server-side session, CSRF, same-origin login/logout,
  bounded form parsing and MySQL-backed attempt throttling and audit.
- Current routes: GET/POST `/admin/login`, GET `/admin` and POST
  `/admin/logout`. The POST responses are JSON (not browser redirects).
  No user or client administration actions are exposed yet.
- `/v1` stays blocked and `GATEWAY_PROXY_ENABLED` does not enable it.

## Private .env setup

The only new configuration is:

```dotenv
ADMIN_UI_ENABLED=false
ADMIN_UI_ALLOWED_IP=
LOGIN_RATE_LIMIT_KEY=
```

Keep the flag **false** until the first admin exists and both TLS/IP
metadata and server-side session behavior have been tested. The rate-limit
key must be 32 cryptographically random bytes encoded as exactly 64 lowercase
hex characters. Generate this using a trusted password manager's
cryptographic generator; do not use a human-made phrase. Store only in
the private .env; never send this key over chat or add it to Git.

Do not reuse `APP_KEY`, the bootstrap setup token or its digest for
login throttling.

## Deliberate enablement checklist

1. Confirm that all three migration rows are `completed`, and that
   the database schema is unchanged from the reviewed migrations.
2. Provision the first administrator only after verifying the
   installer TLS/IP controls; disable and remove the installer secret
   immediately afterward.
3. Confirm PHP provides accurate `HTTPS=on` and source
   `REMOTE_ADDR` at the shared hosting boundary; never bypass this by
   trusting arbitrary client-provided forwarding headers.
4. Prepare a dedicated random key and record the actual operator IP.
5. Temporarily enable the private flag, test sign-in, role checks,
   throttling, CSRF, logout and 30-minute session expiry.
6. Disable the flag immediately if behavior differs from expectations.
   PHP/server integration tests have not yet been run in this release.

## No DB import required

The three migrations are already confirmed installed by the operator.
No SQL changes, no new tables and no migrations should be run again.

On Mac, only `git pull --ff-only origin main` is needed; cPanel Git Version
Control deploys public/index.php to the existing Document Root.
`.htaccess` must remain under cPanel management.
