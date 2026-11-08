# Browser authentication foundation (internal only)

This module introduces PDO-backed eligible account lookup, password
verification, strict browser session initialization, session ID rotation,
30-minute absolute authentication expiry, per-request account eligibility
rechecking, and same-origin CSRF token helpers.

## Current API status

No login, logout, registration, administration or Openprovider route is
published. The current production front controller only supports
`GET /health` and a fail-closed `/v1` placeholder.

## Security rules for future web controllers

1. Login accepts an email and password only after HTTPS, request size limits,
   throttling (IP + identity), brute-force detection and generic error handling.
2. Account eligibility requires `users.status='active'`, non-null
   `email_verified_at` and `approved_at`; no implicit approval on login.
3. Upon successful login, rotate the session ID before storing the user ID.
4. Use secure, HttpOnly, SameSite=Strict session cookies; never store sessions
   in browser localStorage. Prefer private session storage under the account.
5. On each privileged request check the account again from the database.
   Manager authorization must additionally check role membership on each
   administrator operation.
6. Validate the CSRF token for every session-authenticated state-changing form.
   A token does not replace Origin/Referer and content-type controls.
7. On logout, clear the identity, session data and cookie. On privilege change,
   explicitly invalidate outstanding sessions; an individual eligibility check
   alone does not address changes of roles or IP policy.
8. Never log a password, session ID, CSRF token, recovery link or API key.

Before publishing login, add persistent, concurrency-safe login rate limiting,
recovery flows, manually provisioned administrator accounts, CSRF-protected controllers,
session lifecycle tests, security audit events and suitable email verification.

## Deployment

No new SQL schema is needed for this internal module; it uses the existing
confirmed `users` table. No production session is started unless
`BrowserSession::start` is explicitly invoked. No extra server tool,
Terminal, Docker or Mac CLI is required.

The Git deployment process remains manual through cPanel and does not
overwrite MultiPHP-managed `.htaccess`.
