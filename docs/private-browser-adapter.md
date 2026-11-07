# Prepared private browser HTTP adapter

This change adds code for validating bounded form submissions and for
dispatching a small set of browser routes. It is deliberately **not
registered in public/index.php** and has no public HTTP effect.

## Components

- `BoundedRequestBody`: requires an explicit, strictly formatted
  Content-Length; reads at most 16 KiB + 1 and rejects mismatches and
  excess. Do not log bodies.
- `StrictForm`: parses URL-encoded form submissions with exact allowed
  fields, no duplicates, no PHP array syntax, no malformed percent escapes.
- `PrivateBrowserAdapter`: prepared dispatcher for GET /admin/login,
  POST /admin/login, GET /admin and POST /admin/logout. It requires an
  already-started secure PHP session, an independently verified HTTPS
  boundary, trusted correlation ID and guarded login service.

## Important deployment restrictions

1. The dispatcher **must not be mounted** while the first-administrator
   installer, persistent security counters, and safe audit storage remain
   incomplete.
2. The eventual web entrypoint must validate the server's own TLS state,
   initialize the session only after successful TLS validation, enforce
   request limits and set strict CSP/referrer/clickjacking headers.
3. Login and logout must remain side-effect-free for cross-origin requests.
   A server-generated CSRF token must be read from the same session used
   by the controller.
4. Public `/health` behavior is unchanged; `/v1` still returns 503.
   No database migration or phpMyAdmin action is needed for this code-only
   change.
5. Integrate and test MySQL-backed sessions, rate limiting, audit writes
   and browser redirects before enabling the administrative route.
6. An application response should not expose the reason an account is
   pending, disabled or unauthorized.

This hosting workflow requires Git on the Mac and only cPanel's graphical
tools on the server. No Docker, shell, or GitHub workflow is used.
