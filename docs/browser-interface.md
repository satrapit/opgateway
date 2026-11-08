# Browser login and dashboard views (not exposed)

This stage adds private, dependency-free HTML rendering and a login/logout
controller. The public front controller `public/index.php` remains unchanged:
`GET /health` is available; `/v1` remains denied; `/admin/*` remains
unrouted (404). The operator must not manually expose the classes as
standalone public PHP scripts.

## Internal flow

- `BrowserViews::login` generates an HTML form with a server-issued CSRF
  token, email and password fields. It contains no external JavaScript or
  third-party assets.
- `BrowserAuthController::login` checks verified HTTPS, POST, strict
  same-origin, form Content-Type and a session-owned CSRF token.
- `GuardedLoginService` performs persistent throttling before password
  verification, with sanitized audit events. A successful identity also
  must pass fresh database administrator-role authorization.
- On success, `BrowserSession::authenticate` rotates the session ID.
- `BrowserViews::dashboard` escapes displayed identity values and provides
  an authenticated logout form.
- Logout requires a valid session and CSRF token.

## Prerequisites for enabling routes

1. Implement a trusted web-server HTTPS adapter and cap body reads and
   `Content-Length` **before** decoding form values.
2. Reject duplicate form parameters, oversized field values, malformed
   content types and unsupported methods.
3. Add production-ready, migration-ledger-backed storage for login attempts
   and sanitized audit events; the existing initial database remains intact.
4. Create the first administrator manually in phpMyAdmin and confirm
   the public setup route does not exist.
5. Add same-origin response protection headers (strict CSP, no-store,
   referrer policy, frame denial), review inline CSS CSP compatibility.
6. Test login, session fixation, CSRF, database failure, brute force,
   logout and inactive-user transitions against the actual PHP/MySQL stack.

No standalone HTTP routing has been activated. No MySQL modification or
cPanel setting change is needed to fetch this code.

## Deployment constraints

Only Git from Mac and standard cPanel deployment from the hosting panel;
no server-side terminal, Docker, workers, Composer commands or workflows.
Secrets live in private environment configuration and must not enter logs.
