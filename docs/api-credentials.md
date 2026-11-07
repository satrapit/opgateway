# API credential foundation — internal only

The existing `api_credentials`, `api_clients`, `users`, and
`client_ip_whitelists` tables suffice. **No new SQL import is required.**

## Credential format

A key has the format `opg_<16 hexadecimal prefix>.<64 hexadecimal secret>`.
The 256-bit secret is generated using `random_bytes(32)`, and only the
indexed prefix plus `SHA-256` digest of the secret are stored. Hashing this
high-entropy random secret is different from hashing human passwords, which
must use `password_hash`.

`ApiCredentialService::issue` returns the full key once to trusted code
only, after a database-authorized issuance for the active, verified,
approved owner and active client. A future authenticated dashboard must
show the plaintext once over HTTPS and never log, repeat or email it.

`authenticate` requires a correctly formatted secret, a non-revoked and
unexpired credential, an active client, an active verified approved owner,
and an explicitly enabled, approved source IP in the whitelist.
`revoke` is restricted in SQL to an eligible owning user.

## Security and deployment limitations

This is private application code, **not wired to HTTP**. No login,
registration, client-management, API proxy or key-issuance endpoints are
published. All `/v1` routes remain HTTP 503.

Before enabling a single external endpoint, add:
- Secure administrator bootstrap and authenticated control panel.
- Permission checks and ownership for each operation/resource.
- Anti-abuse rate limits, request limits and sanitized audit logs.
- Transport-verified source IP resolution (never trust arbitrary
  `X-Forwarded-For`), CSRF protection for browser actions.
- End-to-end integration tests for PDO queries and concurrent revocation.

The sole hosting interface remains cPanel Git Version Control, File Manager
and phpMyAdmin. The Mac is used only for Git; no Docker or server shell.
No schema import or deployment action is required for these internal classes.
