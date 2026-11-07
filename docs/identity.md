# Identity foundation (not exposed)

The initial registration layer is implemented as **internal PHP services only**.
There is **no registration/login endpoint**, no admin bootstrap user, no session
authentication, no email verification sender and no account approval controller.
All Openprovider routes remain blocked.

## Registration rules

- Normalize email to trimmed, lowercase ASCII and validate syntax/length.
- Require a password of 12–1024 bytes, rejecting NUL bytes. Future iterations
  must assess Unicode normalization and leaked-password screening.
- Hash with PHP `password_hash(PASSWORD_DEFAULT)` and verify with
  `password_verify`; never store passwords in plaintext.
- Insert the new user with status `pending`, no `email_verified_at`, and no
  `approved_at`. Pending accounts have no privileges.
- Let the database enforce a unique email and propagate duplicate-key errors
  **only inside trusted application code**. Public registration must later
  return a non-enumerating response.
- Use database parameter binding; never interpolate user input into SQL.

## Required security controls before any public endpoint

1. Per-IP and per-identity throttling, request-size limits and bot-abuse resistance.
2. Strict Content-Type parsing, HTTPS, appropriate CSRF protection for
   cookie/session-based forms and secure session cookies.
3. Email ownership confirmation with short-lived, single-use hashed tokens.
4. Administrator approval performed only by an authorized account.
5. A safe, one-time administrator bootstrap process on shared cPanel hosting.
6. Account lockout/recovery, generic login and registration errors,
   password rehashing and authenticated session rotation.
7. Authorization checks, audited state changes and tested tenant boundaries.

The Mac workflow requires only Git. No terminal, Docker or executable
processes are required on the hosting account. New schema changes must use
versioned, reviewed phpMyAdmin imports. This change requires **no new SQL**.

Do not deploy registration to production traffic until the above controls
and server-side test coverage are complete.
