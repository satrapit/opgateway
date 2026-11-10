# Security model

## Trust boundaries

Browser/operator → gateway; API client → gateway; gateway → database; gateway → Openprovider. Only server-controlled TLS and source-IP metadata are trusted. Do not infer access from unverified `X-Forwarded-For` or `X-Forwarded-Proto`.

- In the proposed next release, Admin browser login requires HTTPS, exact configured Host, explicit enablement, secure session, live account and administrator role checks, CSRF and database-backed throttling; the old one-operator-IP browser gate is retired.
- Users require active status, verified email and administrator approval before login or client access.
- Browser password hashes use PHP `password_hash`/`password_verify`; session IDs rotate on login. Session cookies are Secure, HttpOnly and SameSite=Strict.
- High-entropy, single-use email verification tokens are SHA-256 hashed in storage. The new public signup marks an account with signup_source=self_service; only these accounts become active/approved after email verification. Existing manual accounts still require admin approval.
- Only explicitly allowlisted administrator lifecycle mutations can be routed, behind the additional default-off `ADMIN_ACTIONS_ENABLED` flag. They enforce live role and owner checks, strict session CSRF, administrator- and IP-scoped limits, bounded form input and audit in the same transaction as the SQL state change.

## Client credential and IP policy

- The internal credential format is `opg_<16 hex prefix>.<64 hex secret>`. The random 256-bit secret is shown once by trusted code and never stored raw; the database stores a prefix and SHA-256 hash. Human passwords use a password KDF instead.
- Credentials are checked against active, verified, approved owner, active client, expiry/revocation and an explicitly enabled **exact IPv4 or IPv6** allowlist entry. No default IPs, CIDR shortcuts or implicit trust.
- Internal creation starts clients as `disabled`; administrator approval is required to activate clients and pending IP entries. Disabled clients cannot use credentials.
- These checks are **not yet part of a public `/v1` HTTP admission pipeline**; no external API call is authorized by these helpers alone.

## Provider and audit security

No arbitrary proxy. Before enabling any provider operation, require a verified versioned path/method allowlist, authenticated client, per-client IP authorization, scopes, tenant/resource ownership, server-held upstream token, TLS/host validation, bounded payloads, safe retry rules and no secrets in responses or logs.

Only allowlisted metadata enters security and diagnostic logs: timestamps, random request IDs, restricted event names, status, and safe numeric database categories. **Never log** passwords, bearer tokens, raw request bodies, email addresses, source IPs, session cookies, CSRF tokens, SQL or stack traces.

Future hardening includes administrator MFA, data-retention/pruning policy, external API audit and verified concurrency behavior. A rejected HTTP request is not a packet-level firewall drop.

## Owner credential portal

Owner login and key actions are behind two independent default-off flags. Password sign-in has separate IP/identity limits; issuance requires password reconfirmation, a fresh eligible owner session and active owned client, and both issue/revoke use CSRF rotation, separate HMAC limits, SQL ownership checks, and metadata-only transactional audit. Secret bearer values never appear in GET views, session data, URLs, application logs or database columns. All public `/v1` provider routes remain disabled.


## Next release encryption, SMTP and proxy (unreleased)

See gateway-onboarding.md. User passwords stay one-way hashed; Openprovider
and SMTP passwords and Openprovider access tokens use AES-256-GCM with random
nonces, authenticated per-user record context and a separately generated
64-hex-character APP_KEY stored in private .env. Never commit APP_KEY or any
credential. Do not expose decrypted values in dashboard/API/logs.

The new API edge never trusts client-supplied forwarding headers; a gateway
API key is looked up by digest, and the client/source IP, owner and enablement
are revalidated in MySQL. Upstream HTTP is fixed to the Openprovider HTTPS
authority, disallows redirects, and enforces JSON/size/time bounds.
GATEWAY_PROXY_WRITE_ENABLED stays false until paid-operation ownership, scopes
and idempotency are independently reviewed. This patch does not attest full
OpenAPI conformance or production readiness.
