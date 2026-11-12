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


Password registration POST requires `password_confirmation` and rejects
mismatched passwords server-side before creating an account. When Google is
configured, Continue with Google is also a first-registration path and creates
a verified, approved, active user with `password_hash = NULL`, links the signed
Google subject transactionally and signs the user in immediately. It never
creates a dummy password. Account sections `/account`, `/account/provider`,
`/account/clients`, `/account/keys` and `/account/ips` are separate routes and
render distinct Twig sections, not a single anchor-based page.

Password recovery: `/account/forgot-password` requests a generic-response,
rate-limited email with a 15-minute, single-use reset link. Only a digest
is stored in MySQL. It can establish a password for an eligible Google-only
account even while Google login is disabled. The reset POST validates password confirmation and
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

## Account Security and Google sign-in — unreleased

`/account/security` shows the available authentication methods. A Google-only
user can set a local password; a password user must provide the current
password to change it. Password set/change/reset increments a server-controlled
session version and revokes older browser sessions.

Continue with Google validates issuer, audience, signature, nonce, PKCE,
one-time state and `email_verified`. Stored `sub` is the only repeat-login
identifier. A new subject with no local email row creates a passwordless,
verified, approved active account and signs in immediately. A signed Gmail
address, or a signed Google Workspace `hd` claim that exactly matches the email
domain, is provider-authoritative: it may link an otherwise eligible account
with that exact email, preserve its password, increment the authentication
session version and sign in. It may also finish a pending public self-service
account. Manual/invited pending accounts and disabled or suspended accounts are
never activated by this path. Other verified-email collisions still require a
15-minute single-use mailbox confirmation bound to the consumed OIDC
transaction and intended user. Email equality alone never authenticates or
links an external-domain account.

Password-registration verification links do not mutate state on their initial
GET. The rendered same-origin page automatically submits the existing
CSRF-protected POST using the local bundled script, so the normal browser flow
is one click; a visible Verify email button remains for browsers without
JavaScript. Verification activates only eligible `self_service` accounts and
does not sign the user in. Basic GET-prefetch scanners cannot consume the
token. A scanner that executes page JavaScript and submits forms cannot be
reliably distinguished from a person without requiring a deliberate second
action, so that residual limitation remains a release-acceptance consideration.

The password-confirmed Connect Google POST redirects only to the same-origin
`/account/google/continue` page. A five-minute, one-use session grant is checked
against the freshly eligible signed-in account before OIDC state, nonce and
PKCE are created. The page uses a no-referrer immediate navigation to the fixed
Google authorization endpoint and keeps a visible keyboard-native link for
manual continuation. This avoids weakening `form-action 'self'` and works
without JavaScript.

Unlink requires a current local password and is denied when Google is the last
method. API-key issue and Openprovider credential changes accept either the
current password or a five-minute, one-use Google verification bound to the
specific account and action. The step-up request requires Google's signed
`auth_time` claim and rejects provider authentication older than five minutes.
No Google access/refresh token is stored.

Requires administrator Google settings and migrations 007–008. Not released.


## First and last name

The single `users` table holds identity records for both administrators and account users. Migration 009 adds nullable first_name and last_name. New Google users may receive both from signed OIDC `given_name` and `family_name` claims (scope `profile`); otherwise the user completes `/account/profile`. Password users complete these fields after verification and login. Incomplete account profiles cannot perform account mutations, and the dashboard displays a persistent completion link.
