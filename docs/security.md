# Security model and threats

Security goals: no cross-tenant exposure, no open proxy, no secret disclosure, no unintended charged mutation.

Trust boundaries: browser/session -> gateway, client API -> gateway, gateway -> database, gateway -> provider, and optional trusted reverse proxy -> application. Only a configured trusted reverse proxy may supply forwarding headers.

Mandatory future controls: secure credential hashes and rotation, email verification and admin approval, administrator MFA, CSRF protection for sessions, deny-by-default IP restrictions, route/operation permission matrix, verified resource ownership, upstream egress host allowlist, TLS verification, disabled redirects, response/header redaction, safe timeout/retry classification, audit trail and retention cleanup.

Current implementation denies all `/v1/*` requests; it does **not** yet authenticate users or enforce allowlists in a live request pipeline. The helper `IpAddress::allowed` provides exact matching only. The `/health` endpoint must not disclose provider or database diagnostics. Do not enable arbitrary proxy forwarding.

Security limitations of shared cPanel hosting: no packet-level firewall controls inside the app, no guaranteed background worker or distributed cache, and varying filesystem isolation. HTTP 403 is an application rejection, not packet drop.
