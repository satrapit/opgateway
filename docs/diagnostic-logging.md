# Production diagnostic logging

OPGateway writes one JSON object per diagnostic event to the private
`/home/arsamnet/repositories/opgateway/storage/logs/opgateway.jsonl`.
Create the `storage/logs` directory with cPanel File Manager and keep
it writable by PHP but **outside the public Document Root**. Restrict
directory permissions to 0700 when the hosting account supports it.
Never make this log file available through HTTP.

Every entry contains `timestamp_utc`, `request_id`, `event`,
`http_status`, and `error_category`. A PDOException also adds a
`database` object with `reason`, allowlisted `mysql_errno`, and
allowlisted two-character `sqlstate_class`. For constructor-time PDO exceptions without `errorInfo`, only a strict
`SQLSTATE[XXXXX] [NNNN]` numeric prefix can be parsed; the rest of
the message is discarded. Native numeric codes within the bounded
range are retained, even when the reason is unclassified. Raw error
messages remain excluded. An optional `context` object
contains only fixed enum values. The corresponding response header is
`X-Request-ID`.

For example (synthetic, no real server information):

```json
{"timestamp_utc":"2026-11-07T19:00:00Z","request_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","event":"setup.database_connection_failed","http_status":503,"error_category":"database","database":{"reason":"authentication_rejected","mysql_errno":1045,"sqlstate_class":"28"},"context":{"operation":"connect"}}
```

Never log the raw PDO message, SQL, credentials, connection DSN, IP,
account email, token, cookie, request payload, stack trace or
environment values. No raw user-controlled context is accepted.

## Error stages

- `setup.configuration_invalid`: missing or invalid setup flags/keys.
- `setup.database_driver_unavailable`: PDO MySQL driver missing.
- `setup.database_connection_failed`: PDO connection/config exception.
- `setup.schema_inspection_failed`: ledger query or checksum verification.
- `setup.schema_incomplete`: a required migration is not marked completed.
- `setup.identity_state_query_failed`: read-only users/roles count failed.
- `setup.already_initialized`: some user or role already exists.
- `setup.session_start_failed`: unable to establish secure PHP session.
- `setup.content_type_denied`: invalid form POST Content-Type; no headers recorded.
- `setup.provisioning_failed`: first-admin token/registration or database operation failed; no secret details recorded.
- `setup.administrator_created`: first administrator successfully created.
- `admin.database_connection_failed` and `admin.schema_inspection_failed`:
  same boundary for separately gated private admin UI.
- `http.unhandled_exception`: last-resort handler.

## Interpreting database.reason

- `authentication_rejected` (1045): check actual MySQL password and user
  authentication in cPanel; avoid printing values.
- `access_denied` (1044/1142/1143/1227): review user-to-database grants.
- `unknown_database` (1049): verify the database name.
- `missing_table` (1146) / `missing_column` (1054): inspect the schema
  with phpMyAdmin; do not reimport blindly.
- `connection_failure` (2002/2003/2005/2006/2013): verify host `db` on
  this hosting provider and MySQL service availability.
- `connection_limit` (1040/1203): check database account connection
  limits.
- `authentication_plugin` (1524/2054): server/client authentication plugin problems.
- `unclassified`: the driver returned an unknown code; no raw details
  are leaked.

## cPanel PHP Selector

On some CloudLinux/cPanel installations, `nd_pdo_mysql` is the MySQL
PDO driver backed by mysqlnd, even when the checkbox called `pdo_mysql`
is not selected. Therefore check the **actual active PHP runtime**
with `PDO::getAvailableDrivers()`, rather than inferring driver support
from checkbox names alone.

Do not expose phpinfo or a diagnostic route to the public. This project
does not need a publicly accessible troubleshooting endpoint.

## Operator procedure

1. Git pull on Mac, then cPanel Git Version Control Update / Deploy.
2. Confirm private log directory exists and is writable.
3. Keep the setup switch OFF until a controlled, HTTPS + IP-restricted
   investigation. Request the setup GET page only once.
4. Read the newest entry, correlate it via X-Request-ID, and report only
   event, error_category, database reason / errno / class if present.
5. If the cause is found, turn setup OFF while fixing it.

No database migration is required for this logging improvement. Historical
log entries cannot be retroactively expanded, so make one new controlled
request after updating the code.
