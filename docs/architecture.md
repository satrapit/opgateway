# Architecture and current state

## Deployment

OPGateway is a PHP 8.3 modular monolith: `public/index.php` is the only deployed PHP entrypoint, while `src/`, `.env`, and `storage/logs` remain outside Document Root. The application runs on shared cPanel without a shell, Redis or long-running workers. Native PDO is used throughout; no ORM or MySQLi fallback.

## Implemented and routed

- `GET /health`: static health response, no DB or provider connection.
- `GET /admin/login`, `POST /admin/login`, `GET /admin`, `POST /admin/logout`: conditional on `ADMIN_UI_ENABLED=true`, trusted HTTPS and an exact server-observed operator IP.
- Admin login checks active, email-verified, approved account and fresh `admin` role membership; session authentication uses PHP server-side sessions, rotating the ID and enforcing 30-minute absolute lifetime. The protected `/admin` page displays bounded approval queues. Approve-user, activate-client and approve-IP forms are independently gated by `ADMIN_ACTIONS_ENABLED=false` by default.
- `GET/POST /account/login`, `GET /account`, `POST /account/logout`: separate default-off `ACCOUNT_UI_ENABLED` gate. Approved accounts use login rate limits, secure session and CSRF; the administrator IP restriction does not apply to owner login.
- `POST /account/keys/issue` and `/account/keys/revoke`: additional default-off `ACCOUNT_KEYS_ENABLED` gate, owner-scoped SQL authorization, CSRF, separate rate limits, transactional audit, and password reconfirmation for issuance. Bearer keys appear only once in the POST response.
- Credentials/passwords are never included in logs.
- `/v1` and `/v1/*` return `503 gateway_not_configured` regardless of `GATEWAY_PROXY_ENABLED`.

## Internal components, not public endpoints

- Registration, verification, account approval and user repositories.
- Public owner self-service registration and bearer authentication at the HTTP edge, although internal account/client/key services exist.
- The older JSON `AdminController` remains internal and unrouted. Seven allowlisted administrator operations are reachable through two strictly validated, session-protected HTML form routes only if the independent mutation feature flag is enabled: approve user/client/IP, create disabled client, request a disabled IP, disable client and revoke IP.
- The migration runner is for controlled offline use; cPanel deployments never run migrations.

The fixed migration schema contains `users`, `roles`, `user_roles`, `api_clients`, `api_credentials`, `client_ip_whitelists`, `email_verification_tokens`, `login_rate_limits`, `security_events` and `schema_migrations`.

## Next phases

1. Validate approved-owner login, password reconfirmation, one-time key issuance and revocation in local MySQL and through trusted HTTPS before enabling account access in production.
2. Complete bearer authentication at the HTTP edge, strict per-client source IP checks, scope/ownership enforcement, rate limits and request-level sanitized audit.
3. Pin and verify the provider API specification; implement restricted outbound transport, secure token management, safe retries and route-by-route compatibility.
4. Only after end-to-end verification, selectively enable explicitly approved `/v1` operations.

At no stage should an arbitrary provider proxy route or user-controlled upstream target be made public.


## Proposed next phase (unreleased)

Additional modules: Mail (administrator SMTP settings and verification
delivery), Provider (encrypted owner account and strict HTTPS upstream),
Security (AES-GCM envelopes and isolated gateway throttles), and a
separately opt-in HTTP gateway edge. Account registration uses existing
login and token services with public routes and a provenance column.
Browser IP gate for administrators is removed; role checks remain.
API source IP admission remains exact for every client. See
docs/gateway-onboarding.md. A complete version-pinned OpenAPI
contract, granular scopes and paid-operation safety are still pending.
