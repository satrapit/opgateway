# Security model

## Trust boundaries

Browser/operator → gateway; API client → gateway; gateway → database; gateway → Openprovider. Only server-controlled TLS and source-IP metadata are trusted. Do not infer access from unverified `X-Forwarded-For` or `X-Forwarded-Proto`.

- Admin routes require HTTPS, explicit enablement, exact operator IP, secure session, live account and role checks, CSRF and database-backed throttling.
- Users require active status, verified email and administrator approval before login or client access.
- Browser password hashes use PHP `password_hash`/`password_verify`; session IDs rotate on login. Session cookies are Secure, HttpOnly and SameSite=Strict.
- Email verification tokens are high-entropy, single-use and SHA-256 hashed in storage; email verification does not imply admin approval. There is no public verification or signup route yet.
- Administrative approval and client operations are private services today; mutation routes must enforce fresh role checks, separate action policies and audit before release.

## Client credential and IP policy

- The internal credential format is `opg_<16 hex prefix>.<64 hex secret>`. The random 256-bit secret is shown once by trusted code and never stored raw; the database stores a prefix and SHA-256 hash. Human passwords use a password KDF instead.
- Credentials are checked against active, verified, approved owner, active client, expiry/revocation and an explicitly enabled **exact IPv4 or IPv6** allowlist entry. No default IPs, CIDR shortcuts or implicit trust.
- Internal creation starts clients as `disabled`; administrator approval is required to activate clients and pending IP entries. Disabled clients cannot use credentials.
- These checks are **not yet part of a public `/v1` HTTP admission pipeline**; no external API call is authorized by these helpers alone.

## Provider and audit security

No arbitrary proxy. Before enabling any provider operation, require a verified versioned path/method allowlist, authenticated client, per-client IP authorization, scopes, tenant/resource ownership, server-held upstream token, TLS/host validation, bounded payloads, safe retry rules and no secrets in responses or logs.

Only allowlisted metadata enters security and diagnostic logs: timestamps, random request IDs, restricted event names, status, and safe numeric database categories. **Never log** passwords, bearer tokens, raw request bodies, email addresses, source IPs, session cookies, CSRF tokens, SQL or stack traces.

Future hardening includes administrator MFA, data-retention/pruning policy, external API audit and verified concurrency behavior. A rejected HTTP request is not a packet-level firewall drop.
