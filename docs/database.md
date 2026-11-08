# OPGateway database configuration

## Shared cPanel hosting

This provider specifies the MySQL host **`db`**, not `localhost`.
The *private* application configuration at
`/home/arsamnet/repositories/opgateway/.env` must contain:

```dotenv
DB_HOST=db
DB_PORT=3306
DB_NAME=YOUR_EXISTING_DATABASE_NAME
DB_USER=YOUR_EXISTING_FULL_DATABASE_USERNAME
DB_PASSWORD=YOUR_PRIVATE_DATABASE_PASSWORD
```

The database and username are not assumed to match historical examples:
use the account and database already verified with the independent
MySQL test. Keep all credentials outside Document Root and Git.
Do not add obsolete `DB_UNIX_SOCKET`, `DB_DRIVER`,
`DB_DIAGNOSTIC_MYSQLI_ENABLED`, `DB_CONNECTION_TEST_ENABLED` or
`DB_CONNECTION_TEST_ALLOWED_IP`.

Database queries and transactions use the single native PDO connection
factory; no ORM, Medoo or second MySQLi connection is deployed.
`/health` does not connect to MySQL and is not proof of DB readiness.

## Existing database / manual first administrator

The database and migrated data are authoritative. **Do not recreate,
reimport or delete any tables or users.** Use phpMyAdmin to confirm the
three completed migration ledger entries in `schema_migrations` and
that `users`, `roles`, and `user_roles` are empty before executing the reviewed manual administrator SQL. If they are not
empty, stop and inspect existing identities rather than clearing them.

For a **brand-new empty database only**, the one-time installation SQL
is `database/sql/opgateway_full_schema.sql`. It includes all three
reviewed migrations and their checksums. Follow
[phpMyAdmin installation](phpmyadmin-install.md). No migration runs
on cPanel deployment.

See [manual administrator SQL](manual-administrator-sql.md). No setup
endpoint, setup token, or temporary setup flags are needed.
