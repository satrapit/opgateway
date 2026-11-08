# Existing databases and rebuilding

For an EMPTY database, import `database/sql/opgateway_full_schema.sql`
once as documented in `docs/phpmyadmin-install.md`.

For an existing database, never import the full schema on top of tables
or data. A database connection failure can be caused by host, credentials
or permissions even when migration records/checksums are valid. Rebuilding
is not a proven fix. This hosting provider requires DB_HOST=db.

Always back up before deliberately discarding an existing database.
No automatic schema recreation or cleanup is performed by this project.
