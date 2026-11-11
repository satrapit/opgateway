# Security model

The gateway trusts only web-server-provided HTTPS and transport source IP,
never client-provided `X-Forwarded-For` or `X-Forwarded-Proto`. The
canonical host must match configured `SERVER_NAME`. All authenticated
pages require fresh account eligibility and role checks. Admin login can
originate from any IP; **API clients cannot**, because the exact source
address must have prior approval for the active client.

Passwords used for browser authentication remain one-way hashed.
Self-service email tokens are random, one-use and SHA-256 hashed in MySQL.
Only self-service accounts are activated through email verification; old
manual accounts retain administrative approval. Sessions are Secure,
HttpOnly, SameSite=Lax, regenerated on authentication, and protected
by CSRF and SQL-backed HMAC-isolated throttling.

Admin review/provision requires the existing `admin` role, current
authorization, CSRF, strict bounded forms, rate limits and transactional
metadata-only audit. Owner API key issue requires fresh password
confirmation. Newly created clients are disabled and IPs are unapproved;
access requires explicitly approved exact IPv4/IPv6 plus an active owner,
active client and valid non-revoked gateway bearer digest.

Account HTML uses the same local, versioned TailAdmin CSS/JS and strict
no-inline-style/script CSP as Admin. Twig autoescapes owner metadata and
never reflects stored provider passwords. The one-time issued API key is
rendered only in a POST response, never placed in query strings,
localStorage or logs. Owner routes retain existing HTTPS, CSRF, secure
sessions, owner authorization and password reauthentication.

## SMTP, provider and application key

All global settings live in MySQL `settings` as key/value rows;
SMTP uses `smtp.*` keys with encrypted `smtp.password`, edited in the
administrator Settings screen after current-password recheck.
Openprovider reseller credentials and cached upstream bearer reside in
`provider_connections`. These values are AES-256-GCM encrypted with
separate authenticated contexts. Decryption requires a 256-bit key
stored at private `storage/app-secret.key` and **not in the database**.
A distinct rate-limiter pepper is derived using HKDF. Keep the key
outside the public Document Root with mode 0600 and back it up privately;
do not regenerate after ciphertext has been saved.

`.env` is exclusively database connection configuration. There are
no feature flags: admission is a property of authenticated identity,
ownership and approved routes, not an environment toggle.

No arbitrary URLs, redirects, unbounded bodies, weak TLS or exposed
upstream passwords/tokens are allowed. Provider operations with monetary
or destructive side effects remain unavailable until operation-level
authorization, ownership, idempotency and upstream-spec review.

Security logs contain only allowlisted metadata: request ID, event code,
timestamp, status and safe categories. Never log passwords, bearer tokens,
full request bodies, email addresses, raw source IPs, CSRF, SQL or traces.
Rejected requests return HTTP status codes; the app is not a packet firewall.

## Google OpenID Connect cookie policy (unreleased)

OIDC authorization-code/PKCE uses Google's cross-site **top-level GET**
redirect to `/account/google/callback`. The browser session cookie is
explicit `SameSite=Lax; Secure; HttpOnly` so the callback receives the
same server-side state and nonce (Strict would withhold it). This does not
authorize state-changing GETs or cross-site POSTs: every local mutation
requires a server-side CSRF token, account eligibility and password
reauthentication where appropriate. The callback consumes a session-bound,
short-lived, single-use `state` and verifies the signed ID token and nonce.
Do not change this to `SameSite=None`, enable client-driven redirects, or
remove CSRF protections. See docs/testing.md for real-browser validation.
