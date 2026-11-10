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
- `/admin/owners`: approved owners and disabled client provisioning.
- `/admin/clients`: client activation/disable and IP requests.
- `/admin/ips/pending`: approve exact IP addresses.
- `/admin/ips/approved`: revoke approved IP access.
- `/admin/settings/smtp`: database-backed SMTP configuration.
- `POST /admin/review` and `POST /admin/provision`: role-checked,
  CSRF-protected, strictly validated, rate-limited transactional mutations.
- `POST /admin/logout`: CSRF-protected sign-out.

Only the TailAdmin/Twig interface is instantiated for real requests. All
listings are bounded and authorization precedes data queries. Client
provisioning creates **disabled** clients. IP requests remain pending until
admin approval. Admin lifecycle actions record metadata-only audit events.

## SMTP settings

SMTP settings are stored only in `smtp_settings`, not in `.env`.
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
tests pass. Production migration 004 requires a separate backup and
coordinated deployment.
