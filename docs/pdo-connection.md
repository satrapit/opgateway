# Native PDO-only database access

OPGateway uses PHP 8.3's `pdo_mysql` extension. There is no ORM,
query builder, Doctrine DBAL, Medoo, or alternate MySQLi connection.
Every runtime connection is constructed using
`OPGateway\\Database\\PdoConnectionFactory::open($config)`.

Existing repositories and privileged services receive a `PDO` instance
through their constructor. SQL is always executed via native PDO:
`prepare()` + `execute()` for user-dependent values, `query()` for
fixed read-only statements, and `exec()` for fixed reviewed DDL.
Multi-statement identity operations use PDO transactions.
Prepared statements use native binding (emulation disabled).

Database connection settings in the private .env:
`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`,
with `DB_HOST=db` on this provider. No socket or alternate
connection settings are supported; credentials stay in the private
`.env`.

PHP's `pdo_mysql` extension is required; no extra Composer package
is required for database connectivity. Composer is used for test tooling.

A server-side MySQL error 1524 means the database server cannot load
the configured authentication plugin. Using PDO rather than Medoo or
MySQLi does not resolve that server-side failure by itself.

Do not modify the database schema, migration ledger, users or roles.
Update only DB_HOST and the actual database name/user/password in the
private production .env as required by the hosting provider.
