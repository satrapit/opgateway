# Identity and client access foundations

These services are **private PHP code only**: no public registration, login,
email verification, administrator approval or API proxy routes are active.

## Email ownership verification

- Generate 256-bit secrets using `random_bytes(32)`; store SHA-256 hashes only.
- 30-minute token expiry and atomic one-time consumption under an InnoDB lock.
- Successful verification updates `users.email_verified_at`; it does **not**
  approve the user or grant access.
- Consuming a valid token also invalidates remaining tokens for that user.
- The table `email_verification_tokens` belongs to the second schema migration,
  and is **not yet assumed present in the confirmed initial seven-table DB**.
  Do not instantiate the PDO verification repository until that schema revision
  has been applied via the reviewed phpMyAdmin SQL package.
- No raw tokens in logs, query analytics, request history or error output.

## Administrator approval

`AccountApprovalService` authorizes the actor from DB state on each action:
active and verified account plus membership in the `admin` role. It permits
approval only of a distinct, verified pending user. This service **does not**
create the initial administrator or grant admin roles. The first
administrator is provisioned through reviewed SQL in phpMyAdmin (see
`manual-administrator-sql.md`); later account approval remains separate.

## API client IP allowlisting

`ApiClientIpPolicy` compares the exact 4- or 16-byte address from
`inet_pton` to `VARBINARY(16)` stored data. Authorization also requires
an active API client, an active verified approved owner, an explicitly enabled
IP entry and an approval timestamp. An empty list denies access.

This policy is not yet connected to request middleware. Before exposing any
API endpoint, enforce authenticated credentials, IP restrictions, operation
permissions, tenant ownership, anti-abuse controls and safe request auditing.

## Deployment requirements

Only Git on the Mac and standard cPanel/phpMyAdmin on the shared server.
No Terminal, Docker, background workers, Composer on the server, or automatic
SQL migrations are required. The existing first seven tables are confirmed and
must not be re-imported. Code deployment does not change the schema.
