# Temporary database connection test (private .env)

The test is served by the existing `public/index.php` router at
`/db-connection-test.php`, and executes the private checked-in script
`public/db-connection-test.php`. **It does not need to be copied into
the public Document Root**. The cPanel manifest still only deploys
`index.php`; the private test file is loaded through the application
source outside Document Root.

This temporary diagnostic is **off by default**. It accepts only GET
over HTTPS from the exact IP configured by the operator in the
private repository-root `.env`. Even with a known URL it returns
HTTP 404 until explicitly enabled. Keep `APP_DEBUG=false`.

## How to run one controlled test

1. Update and deploy the Git commit using cPanel Git Version Control.
2. Add these two lines to the PRIVATE
   `/home/arsamnet/repositories/opgateway/.env` file:
   ```dotenv
   DB_CONNECTION_TEST_ENABLED=true
   DB_CONNECTION_TEST_ALLOWED_IP=YOUR_VERIFIED_PUBLIC_IP
   ```
   The IP is the source address seen by PHP `REMOTE_ADDR`, not the
   server IP. Leave `SETUP_ENABLED=false`.
3. Visit `https://opgateway.arsamnet.com/db-connection-test.php`
   in a browser using that same IP. The JSON response displays only
   finite PDO/MySQLi classifications. Both drivers execute `SELECT 1`
   with existing DB credentials; neither writes database content.
4. If HTTP 404 persists, read the latest private
   `/home/arsamnet/repositories/opgateway/storage/logs/opgateway.jsonl`
   entry. `db_test.access_denied` includes only a fixed gate reason:
   `disabled`, `method_denied`, `https_denied`, or `ip_denied`.
   No IP value, password, SQL or server path is logged.
   If there is **no new db_test event**, check cPanel's deployed
   `index.php` version, the PHP handler, and that the private
   `.env` is readable. The virtual URL must route to index.php;
   no independently published test file is required.
5. **Immediately set `DB_CONNECTION_TEST_ENABLED=false`**, and
   optionally remove `DB_CONNECTION_TEST_ALLOWED_IP`. The route
   stays inaccessible under default settings. Remove any manually
   copied `db-connection-test.php` from the public directory as well.

## Typical JSON (fictional)

```json
{"request_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pdo":{"status":"failed","reason":"connection_failure","mysql_errno":2002,"sqlstate_class":"HY"},"mysqli":{"status":"failed","reason":"connection_failure"}}
```

Status 503 means a driver or query failed. The output omits credentials,
hostnames, MySQL messages, file paths, input or request headers. It
does not use Doctrine's Composer vendor dependencies.

**Never recreate database tables because of a connection failure.**
