# Temporary cPanel database-only connection test

The standalone file `public/db-connection-test.php` tests **only**
MySQL connection using values in the private repository-root `.env`.
It performs a read-only `SELECT 1` using the application's PDO factory
and a separate MySQLi probe. It never modifies tables or migration rows.

**The deployment manifest intentionally does NOT publish this file.**
Never leave it in the public Document Root after testing.

## Single controlled test

1. Deploy the Git revision to the private source checkout using cPanel.
2. In private `/home/arsamnet/repositories/opgateway/.env`, add:
   ```dotenv
   DB_CONNECTION_TEST_ENABLED=true
   DB_CONNECTION_TEST_ALLOWED_IP=<your verified direct REMOTE_ADDR>
   ```
   Keep `SETUP_ENABLED=false` and `ADMIN_UI_ENABLED=false`.
   Neither this file nor the existing environment parser requires
   `APP_DEBUG=true`.
3. Using cPanel File Manager, **manually copy** ONLY
   `/home/arsamnet/repositories/opgateway/public/db-connection-test.php`
   to `/home/arsamnet/opgateway.arsamnet.com/db-connection-test.php`.
4. From that exact allowlisted IP, open
   `https://opgateway.arsamnet.com/db-connection-test.php`.
   The response contains only `status` and allowlisted PDO/MySQLi
   error categories and codes, never credentials, SQL, server paths,
   usernames or exception text. A failed test returns HTTP 503.
5. Immediately set `DB_CONNECTION_TEST_ENABLED=false`, remove
   `DB_CONNECTION_TEST_ALLOWED_IP` and **delete**
   `/home/arsamnet/opgateway.arsamnet.com/db-connection-test.php`.

If disabled, called over HTTP, from a different IP or by POST, the
endpoint returns HTTP 404. It has no POST support, no public discovery,
no vendor dependency and no GitHub workflow.

## Example (fictional)

```json
{"request_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","pdo":{"status":"failed","reason":"connection_failure","mysql_errno":2002,"sqlstate_class":"HY"},"mysqli":{"status":"failed","reason":"connection_failure"}}
```

If PDO and MySQLi both fail, inspect the existing DB_HOST, DB_USER,
DB_PASSWORD and account grants in cPanel. Do not recreate tables or
reset the database merely because a connection fails.

**This diagnostic is not a permanent endpoint. Delete the public
copy even if the test fails.**
