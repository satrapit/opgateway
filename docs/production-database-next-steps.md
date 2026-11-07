# Existing databases and rebuilding

For an EMPTY database, import `database/sql/opgateway_full_schema.sql`
once as documented in `docs/phpmyadmin-install.md`.

For an existing database, never import the full schema on top of
tables or data. The current setup error
`setup.database_preflight_failed` with `error_category=database`
can be due to PHP-MySQL connection failure or permissions, even when
migration records/checksums are valid. Rebuilding is not a proven fix.

Always back up before deliberately discarding an existing database.
No automatic schema recreation or cleanup is performed by this project.
