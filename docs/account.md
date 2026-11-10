# Owner portal

All owner pages use the **same TailAdmin/Twig design system** as Admin:
`layouts/auth.html.twig` for fonts/assets and dark/light/system theme,
`layouts/account-auth.html.twig` for sign-in, registration and verification,
and `layouts/workspace.html.twig` for the responsive authenticated dashboard.
The templates live in `templates/account/`; `AccountViews` is a thin Twig
presentation wrapper, not a second hand-coded HTML renderer. Local assets
are shared at `/assets/admin/` until a common asset directory can be
migrated in a separately reviewed change. No duplicate CSS, remote CDN,
additional UI feature flags or new login endpoints are introduced.


Registration POST requires `password_confirmation` and rejects mismatched
passwords server-side before creating an account. The browser form already
includes the confirmation field. Account sections `/account`, `/account/provider`,
`/account/clients`, `/account/keys` and `/account/ips` are separate routes and
render distinct Twig sections, not a single anchor-based page.

Password recovery: `/account/forgot-password` requests a generic-response,
rate-limited email with a 15-minute, single-use reset link. Only a digest
is stored in MySQL. The reset POST validates password confirmation and
CSRF and does not automatically sign the user in. Migration 006 must be
applied manually before using updated source.

Owner login is available at `/account/login`; registration is at
`/account/register`. A new user signs up with email/password, confirms
the 30-minute single-use verification email via a CSRF-protected POST,
and may sign in after verification. Existing manually created users retain
their previous admin-approval requirements.

The system has no account or API-key feature flags. Security is enforced
by real HTTPS, canonical Host validation, MySQL-backed per-identity/IP
throttling, secure server sessions, login/ownership checks, and CSRF.
A missing SMTP configuration prevents new signup instead of silently
creating users without email delivery.

An authenticated owner can store Openprovider reseller credentials
(encrypted in the `provider_connections` table with password recheck),
create disabled API clients, request exact IPv4/IPv6 allowlist entries,
and issue/revoke gateway bearer keys. Admin approval is still required
to activate a client and an IP. API keys are shown once and stored only
as digests. The gateway uses a separate upstream token internally.

The application HTTPS host is configured at the web server. `.env`
contains **only MySQL connection details**. SMTP is managed by the admin
in the database. The application's encryption key is separate from the
database at `storage/app-secret.key`, outside the public document root.

See [onboarding](gateway-onboarding.md) for a local end-to-end checklist.
