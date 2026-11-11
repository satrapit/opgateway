# Administrator panel

The **existing `admin` role is the only privileged operator role**.
There is no `super_admin` role and no feature flags. Admin sign-in works
from any IP, but always requires real HTTPS, an exact canonical virtual
host, active/verified/approved account, fresh role checks, secure sessions,
CSRF and HMAC-keyed throttling.

## Routes

- `/admin/login`: username/password sign-in with CSRF.
- `/admin`: overview.
- `/admin/users`: pending user approvals.
- `/admin/users/invite`: invite a user by email; manual approval follows verification.
- `/admin/clients/create`: create a disabled API client for an approved owner.
- `/admin/ips/request`: request exact IP access pending review.
- `/admin/owners`: approved owners and disabled client provisioning.
- `/admin/clients`: client activation/disable and IP requests.
- `/admin/ips/pending`: approve exact IP addresses.
- `/admin/ips/approved`: revoke approved IP access.
- `/admin/settings/security`: offline image / Google reCAPTCHA v2.
- `/admin/settings/login/google`: Google SSO Client ID/Secret and toggle.
- `/admin/settings/smtp`: database-backed SMTP configuration.
- `/admin/settings` and legacy login settings GET paths redirect to Security.
- `/admin/settings/google`: legacy GET redirects to Google SSO.
- `POST /admin/review` and `POST /admin/provision`: role-checked,
  CSRF-protected, strictly validated, rate-limited transactional mutations.
- `POST /admin/logout`: CSRF-protected sign-out.

Only the TailAdmin/Twig interface is instantiated for real requests. All
listings are bounded and authorization precedes data queries. Client
provisioning creates **disabled** clients. IP requests remain pending until
admin approval. Admin lifecycle actions record metadata-only audit events.

## SMTP settings

SMTP uses seven `smtp.*` keys in the shared key/value `settings` table,
not a dedicated table and never `.env`.
Visit `/admin/settings/smtp` and provide host, port, encryption, account,
password, sender address/name and the **current admin password**.

Supported TLS modes: port 587 STARTTLS or port 465 SMTPS with peer
verification. The SMTP password is encrypted at rest and never displayed
again. Until SMTP has been configured, public registration cannot send
verification mail and its route fails closed. No separate enable switch.

The private encryption key is `storage/app-secret.key`, created once on
the application host with owner-only permissions. Back it up privately.
The web-server `SERVER_NAME` must equal the application's canonical
`HTTP_HOST`, e.g. `opgateway.test` locally.

## Safe local verification

Verify login, admin role revocation, wrong password, CSRF failures and
SMTP configuration. Then test sign-up + email verification with a real
test SMTP account and local DB only. Activate a test client, approve its
exact IP, issue and revoke a one-time key, then test allowed/denied
requests. No destructive operations on real owner records.

Release remains blocked until integrated browser/SMTP/database and provider
tests pass. Production migrations 004–005 require a separate backup and
coordinated deployment.

## Google OAuth settings — unreleased

At `/admin/settings/google`, the existing admin role may configure a
Google OAuth Web Client ID, encrypted client secret, and confirm the current
administrator password. The secret is never returned to the browser.
Use the exact callback URI shown on the page in Google Cloud Console.
The global key/value settings table stores the credentials; never store
OAuth secrets in the .env file or Git. This UI is draft, not alpha.4.

## Administrator creation workflows

Invite an owner at `/admin/users/invite`. SMTP must be configured. The
request requires the current administrator password and a valid CSRF
token. Existing account emails receive a generic result. The invite is
stored as pending with an unusable random password hash and no role grant.
The user receives an email verification link; the administrator approves
them on the Users page after verification. The user then sets a password
using Forgot password. Resending an unverified admin invitation is
supported without creating a duplicate account. Do not send credentials
in invitation email or logs.

Create a client from `/admin/clients/create` (disabled by default),
then activate it on the Clients page. Request an exact IPv4/IPv6 address
using `/admin/ips/request` and approve it on Pending IPs. Both pages
reuse the existing auditable provisioning logic and rate limits. They
do not grant API access without the separate activation and approval.
