# Production database upgrade: verified initial install to login-ready schema

This guide applies ONLY to the existing OPGateway shared cPanel deployment.
The original seven identity tables and migration 001 are confirmed installed.
NEVER rerun the initial SQL or create a new database.

## Step 1 — read-only preflight

In cPanel > phpMyAdmin, select `arsamnet_opgateway_db`. Run
`database/sql/preflight_identity_extensions.sql` in the SQL tab. This script
does not modify anything. It displays the selected database, migration history,
table presence and number of existing accounts.

The initial migration must be marked `completed`. Stop for manual diagnosis
if any row is `running`, any expected existing table is missing, or the
database is not `arsamnet_opgateway_db`.

## Step 2 — import missing migrations IN ORDER

After exporting a verified backup using phpMyAdmin:

- If migration 002 is missing and `email_verification_tokens` is absent,
  import `database/sql/202611070002_create_email_verification_tokens.sql`
  once. Verify its ledger row is `completed`.
- If migration 002 is completed and migration 003 is missing AND both
  `login_rate_limits` and `security_events` are absent, import
  `database/sql/202611070003_login_security.sql` once. Verify its ledger
  row is `completed`.

NEVER re-import a completed migration, skip a migration, or run an import
against a partially created schema. If an import fails, stop immediately;
MySQL DDL causes implicit commits and rerunning can destroy traceability.
Do not manually update checksums or statuses. Contact the maintainer for a
specific recovery plan based on the actual resulting tables and ledger.

The two imports add exactly three tables and two migration records.
With the initial seven confirmed, successful completion means ten tables and
three `completed` migration rows.

## Step 3 — private configuration

The login rate limiter is currently an internal class. It requires a
high-entropy, stable, application-specific secret as its HMAC key. Future
HTTP wiring will load this from a private `.env` key
`LOGIN_RATE_LIMIT_KEY`; that wiring is not yet implemented and setting
it alone does not activate login. Generate the value using a trusted password
manager's cryptographic random generator (at least 32 random bytes).
Never include the key in a Git commit, email, screenshot or support transcript.

All other DB credentials remain solely in the existing private `.env` file.
The public Document Root must not contain that file.

## Step 4 — Git and cPanel deployment

On the MacBook, run only Git:
`git pull --ff-only origin main`, `git status --short --branch`, and
`git log -1 --oneline` from the existing opgateway checkout.

In cPanel Git Version Control, use Update from Remote and deploy the HEAD
commit if you need the new private classes on hosting. The deployment does
not execute migrations or overwrite server-managed `.htaccess`.

No PHP CLI, Composer, MySQL, Docker or server terminal is necessary.

## Public exposure

`/health` stays available. `/admin` remains unmounted and `/v1` stays
fail-closed. Importing the database support tables does not itself enable
the login page or public service. Administrator provisioning, rate-limit
configuration, protected HTTP routes and MySQL integration testing are
outstanding before production activation.
