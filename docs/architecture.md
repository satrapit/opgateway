# Architecture and current state

## Deployment

OPGateway is a PHP 8.3 modular monolith: `public/index.php` is the only deployed PHP entrypoint, while `src/`, `.env`, and `storage/logs` remain outside Document Root. The application runs on shared cPanel without a shell, Redis or long-running workers. Native PDO is used throughout; no ORM or MySQLi fallback.

## Implemented and routed

- `GET /health`: static health response, no DB or provider connection.
- `GET /admin/login`, `POST /admin/login`, `GET /admin`, `POST /admin/logout`: conditional on `ADMIN_UI_ENABLED=true`, trusted HTTPS and an exact server-observed operator IP.
- Admin login checks active, email-verified, approved account and fresh `admin` role membership; session authentication uses PHP server-side sessions, rotating the ID and enforcing 30-minute absolute lifetime. The protected `/admin` page displays bounded approval queues. Approve-user, activate-client and approve-IP forms are independently gated by `ADMIN_ACTIONS_ENABLED=false` by default.
- Credentials/passwords are never included in logs.
- `/v1` and `/v1/*` return `503 gateway_not_configured` regardless of `GATEWAY_PROXY_ENABLED`.

## Internal components, not public endpoints

- Registration, verification, account approval and user repositories.
- API client creation/activation, IP request/approval/revocation and credential issuance/authentication.
- The older JSON `AdminController` remains internal and unrouted. Three explicitly validated `AdminActions` operations can be routed through session-protected HTML forms only if the independent mutation feature flag is enabled.
- The migration runner is for controlled offline use; cPanel deployments never run migrations.

The fixed migration schema contains `users`, `roles`, `user_roles`, `api_clients`, `api_credentials`, `client_ip_whitelists`, `email_verification_tokens`, `login_rate_limits`, `security_events` and `schema_migrations`.

## Next phases

1. Validate the new gated administrator mutations end-to-end in local MySQL and the real cPanel HTTP environment before enabling production actions.
2. Complete API client provisioning, bearer authentication, strict per-client source IP checks, scope/ownership enforcement, rate limits and request-level sanitized audit.
3. Pin and verify the provider API specification; implement restricted outbound transport, secure token management, safe retries and route-by-route compatibility.
4. Only after end-to-end verification, selectively enable explicitly approved `/v1` operations.

At no stage should an arbitrary provider proxy route or user-controlled upstream target be made public.
