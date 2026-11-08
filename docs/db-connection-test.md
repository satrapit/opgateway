# Temporary PDO database connection test

The existing `public/index.php` route `/db-connection-test.php` runs
the private `public/db-connection-test.php` source. Only GET over HTTPS
from the exact source IP configured in the private `.env` is accepted.

```dotenv
DB_CONNECTION_TEST_ENABLED=true
DB_CONNECTION_TEST_ALLOWED_IP=YOUR_VERIFIED_PUBLIC_IP
```

The check uses the **single native PDO connection** and runs only
`SELECT 1`. It never writes or changes the schema. Successful
responses have `pdo.status=connected`. Errors include a finite
reason, an optional numeric MySQL errno and an SQLSTATE class, never
the raw exception, host, password, SQL or filesystem path.

If MySQL errno 1524 persists, it indicates an authentication plugin
problem on the actual database server; switching from Doctrine to
PDO **does not** bypass MySQL's server-side plugin requirements.

Disable `DB_CONNECTION_TEST_ENABLED=false` immediately after testing.
Remove any manual copy of the script from Document Root.

No Composer database adapter or ORM dependency is needed.
