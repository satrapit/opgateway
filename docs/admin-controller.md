# Internal administrative controller (not publicly routed)

The admin stack is implemented in three layers:

- `BoundedJson`: small strict JSON object parsing, exact field sets, and
  positive integer identifiers with no string coercion.
- `AdminController`: derives the actor ID from a secure session, checks
  account eligibility and the administrator role against MySQL, validates
  origin, request method, CSRF and body size via `AdminRequestPolicy`.
- `AdminActions`: allowlisted operations `approve_user`,
  `activate_client` and `approve_ip`. Domain services independently
  enforce the actor's role, resource state and eligibility in SQL.

No caller may accept `admin_user_id`, `is_admin` or authentication flags
from a request body, query parameter, forwarding header or cookie payload.
Unknown operations and extra JSON fields are rejected.

## Still private

**None of these classes is wired to `public/index.php`.** The production
behavior remains `GET /health` and deny-by-default `/v1`. No login form,
installer endpoint, admin dashboard or security-sensitive action is reachable
remotely. This commit adds no schema, SQL import or cPanel setting.

The eventual HTTP adapter must additionally:

1. Determine HTTPS from verified web-server state; never blindly trust
   forwarded headers.
2. Start `BrowserSession` before invoking the controller; on failed start,
   return a generic error rather than bypassing protection.
3. Read at most 16385 bytes from `php://input`, rejecting overflow and
   malformed Content-Length values before accepting requests.
4. Enforce CSRF using a server-issued session token and deny cross-origin
   posts. Enforce an independent endpoint/action rate limit.
5. Apply sanitized structured audit logging, handle PDO failures as generic
   server errors, and never store request bodies or secrets in logs.
6. Integration-test all authorization transitions against real MySQL and
   verify live cPanel behavior before allowing public management actions.

## Operational constraints

Only Mac Git commands and standard cPanel Git Version Control, File Manager,
MultiPHP INI Editor and phpMyAdmin. No server shell, local Docker/PHP,
automated migrations, GitHub workflows or new SQL are required here.
