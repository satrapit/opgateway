# Architecture

OPGateway is a PHP 8.3/MySQL modular monolith for shared cPanel hosting.
The only public PHP entrypoint is `public/index.php`; PHP source,
Composer packages, templates, private `.env`, and private storage live
outside Document Root. There are no GitHub workflows, Redis or workers.

## Routed surfaces

- `/health`: minimal static health response.
- `/admin/*`: the single TailAdmin/Twig administrator interface. HTTPS,
  canonical virtual host, secure session, current account and `admin` role,
  CSRF, throttling and audit protect operations.
- `/account/*`: owner sign-in, email signup and verification, Openprovider
  credentials, pending IP/client requests and one-time API key management.
- `/v1/*`: gateway bearer authentication plus exact approved source IP,
  owner/client checks, bounded JSON and fixed-host HTTPS to Openprovider.
  Financially consequential write operations remain unavailable pending
  explicit permission design, independent of any feature flag.

No `*_ENABLED` gates or dual admin renderers are used for runtime
routing. Browser permissions depend on user state and role. Clients and
IP addresses require explicit admin review before API use.

## Configuration and storage

Five MySQL connection keys are the **only** `.env` entries. Admin-editable
SMTP configuration lives in the `smtp_settings` table. Per-owner
Openprovider credentials are in `provider_connections`. Both use
AES-256-GCM with a separate private, backed-up
`storage/app-secret.key` outside the database. Request throttling uses
a domain-separated HKDF key. Canonical host and TLS are configured in
the web-server virtual host, never accepted from untrusted forwarded headers.

The four immutable migration definitions are applied explicitly in
order; cPanel deploy never auto-migrates. Existing populated alpha.3
databases require the additive migration 004 and a backup before using
the new source. No new schema migration is introduced by the removal
of feature flags.

## Out of scope before release

Version-pinned full OpenAPI compatibility, domain ownership/scope checks
for paid writes, idempotency policy, full end-to-end live SMTP/Provider
and browser verification, password reset and MFA. These are release
hardening tasks, not reasons to add arbitrary runtime switches.
