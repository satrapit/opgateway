# Architecture (proposal; foundation only)

## Modular monolith

PHP 8.3, front controller, explicit middleware pipeline, application services, repositories and a strictly allowlisted upstream adapter. Planned domains: Identity, API Clients, IP Policies, Authorization, Resource Ownership, Provider Integration, Operations, Audit and Developer Tools. All modules are logical boundaries within one deployable application.

## Request lifecycle (planned)

1. Edge TLS and trusted source-IP resolution (never trust a client-provided X-Forwarded-For header).
2. Correlation ID, size limits and endpoint/method allowlist.
3. Programmatic credential authentication (401 when missing/invalid).
4. Exact per-client IP allowlist (403 when disallowed).
5. Endpoint permission and tenant resource ownership evaluation (403 on denied operation).
6. Database-backed, concurrency-safe throttling (429).
7. Operation classification and durable attempt record before any mutation.
8. Provider token selection, secured outbound HTTPS, strict host/redirect rules.
9. Upstream response pass-through where contract-safe; structured sanitized metadata and audit outcome.

## Data model (proposed)

- `users` (id, email unique, password_hash, email_verified_at, approval_status, created_at).
- `roles`, `user_roles`, `permissions`, `role_permissions`.
- `api_clients` (id, owner_user_id FK, status, created_at), `api_credentials` (id, client_id FK, key_prefix, secret_hash, expires_at, revoked_at).
- `client_ip_whitelists` (id, client_id FK, ip_binary, enabled, approved_by, timestamps).
- `client_permissions` (client_id, operation_key, effect, scope).
- `resource_ownership` (id, resource_type, provider_resource_id, owner_user_id, verified_at; unique(resource_type,provider_resource_id)).
- `operations` (id, client_id FK, operation_key, state, upstream_reference_nullable, timestamps); `operation_attempts` (id, operation_id, result, timing_ms).
- `gateway_requests` (id, client_id nullable, sanitized_path_template, method, source_ip_binary, status, latency_ms, timestamp); `security_events`; `audit_events`; `rate_limit_counters`; `provider_token_cache` (encrypted token, key identifier, expiry).

No SQL migrations are included until constraints, SQL compatibility and ownership rules have been reviewed. Sensitive payloads must not be retained in operation tables. Apply foreign keys, indexes, explicit transactions and least privilege.

## Limitations

No upstream endpoint is yet enabled. Shared reseller accounts require verified per-resource ownership and deny-by-default. Filesystem storage and cPanel without workers require database transaction and lock strategies.
