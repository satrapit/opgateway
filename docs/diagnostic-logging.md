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
{"timestamp_utc":"2026-11-07T19:00:00Z","request_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","event":"admin.database_connection_failed","http_status":503,"error_category":"database","database":{"reason":"authentication_rejected","mysql_errno":1045,"sqlstate_class":"28"},"context":{"operation":"connect"}}
```

Never log the raw PDO message, SQL, credentials, connection DSN, IP,
account email, token, cookie, request payload, stack trace or
environment values. No raw user-controlled context is accepted.

## Error stages

- `admin.database_connection_failed`: PDO connection failed for the
  separately gated admin UI.
- `admin.schema_inspection_failed` or `admin.schema_incomplete`: the
  migration ledger could not be read or did not match expected revisions.
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

1. Deploy a reviewed commit and verify private log storage is writable.
2. Keep `ADMIN_UI_ENABLED=false` until a verified manual admin user exists.
3. When testing login, correlate the response `X-Request-ID` with the
   private JSONL event. Never share secrets, raw headers or form payloads.

No public database diagnostic or initial administrator setup endpoint exists.
