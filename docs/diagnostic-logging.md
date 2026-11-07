# Production diagnostic logging

The original foundation suppressed exception details in public responses
but did not persist diagnostic metadata. This was a genuine observability
gap. This change adds a JSON Lines diagnostic sink at
`/home/arsamnet/repositories/opgateway/storage/logs/opgateway.jsonl`.

## Operator setup in cPanel File Manager

1. Open the PRIVATE source tree (not Document Root):
   `/home/arsamnet/repositories/opgateway/storage/`.
2. Create a directory `logs` if absent. Set directory permissions to
   `0700` where supported; it must be writable by the PHP account.
3. Ensure the file is not served publicly or symlinked into Document Root.
   The logger creates `opgateway.jsonl` on first recorded event.
4. Never give the logger write permission to a public directory.
5. Rotate or archive logs through File Manager as needed; logging is not
   a substitute for an automated retention policy.

## Recorded fields

Each line is one JSON object with the following fixed metadata:
`timestamp_utc`, `request_id`, `event`, `http_status`,
`error_category`. The public response carries the same request ID in
the `X-Request-ID` HTTP header.

For example, a redacted synthetic event might look like:

```json
{"timestamp_utc":"2026-11-07T18:00:00Z","request_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","event":"setup.database_preflight_failed","http_status":503,"error_category":"database"}
```

The application never persists the raw exception message, exception
stack, file path, SQL, IP, email, setup token, passwords, headers or body.

## Diagnostic events

- `setup.configuration_invalid`: malformed/missing setup origin, token
  digest, or setup rate limit key.
- `setup.database_preflight_failed`: failed database connection, schema
  query, or mismatched migration checksum; category distinguishes PDO
  exceptions from other runtime errors.
- `setup.schema_incomplete`: one or more required migrations are absent.
- `setup.already_initialized`: a user/role already exists.
- `setup.rate_limited`: the setup attempt limit was exceeded.
- `setup.provisioning_failed`: bootstrap creation denied or failed.
- `setup.session_start_failed` / `setup.session_cleanup_failed`:
  server-side PHP session problem.
- `http.unhandled_exception`: exception escaped controller handling.
- `admin.configuration_invalid` / `admin.schema_incomplete`:
  private admin login preflight failures.

When opening the admin setup form results in HTTP 503:
(1) inspect the last JSONL record; (2) match `request_id` with the HTTP
response header; (3) report only the `event`, `http_status` and
`error_category`. If file creation fails, cPanel/PHP's error log may
contain only the fixed message `OPGateway diagnostic log write unavailable.`.

The logging path does not perform SQL changes, auto-enable setup or
grant access. Keep `SETUP_ENABLED=false` until the deployment and
diagnostic path are verified.
