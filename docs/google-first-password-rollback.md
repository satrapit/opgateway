# Migration 008 and rollback review plan

Status: **unreleased; independent code review and empty-schema MySQL 8.0.46
rehearsal completed 2026-11-12**. Restored-backup rehearsal and manual
acceptance remain pending. This plan is not an authorization to touch
production. OPGateway has one production hostname and no staging clone, so all
release DDL rehearsal must use a disposable local copy of a verified backup.

## Migration invariants

Migration 008 is ordered after the completed/checksummed migration 007. It:

- preserves every existing `users` row and `password_hash` value;
- changes only the hash column nullability;
- backfills `auth_session_version = 1` through a non-null default;
- creates `google_identity_confirmations`; and
- records its immutable checksum in `schema_migrations`.

It does not create passwords, approve accounts, grant roles, deploy code or run
automatically. MySQL DDL may commit independently, so a partial failure requires
inspection, not blind replay.

## Disposable-copy rehearsal

1. Verify the backup can be restored and retain a separately verified backup of
   `storage/app-secret.key`.
2. On the restored copy, verify seven completed migration rows, fourteen final
   tables, zero duplicate user emails and the expected Google subject uniqueness.
3. Record aggregate-only baselines: total users, non-null password hashes,
   Google identities and each account status. Do not export hashes, emails or
   subjects into logs or review notes.
4. Review the generated checksum and every statement in
   `database/sql/upgrade_202611120001.sql`, then execute it once.
5. Verify eight completed migration rows, fifteen final tables, unchanged user
   and non-null-hash counts, `auth_session_version >= 1` for every user, and no
   confirmation rows created by the migration itself.
6. Run the complete application test/build gate and local browser scenarios
   against the migrated copy. Do not use real customer OAuth, SMTP or provider
   credentials in automated tests.

## Maintenance-window ordering

The seven-migration application intentionally rejects migration 008, while the
new application intentionally rejects a database missing 008. If approved for a
future release, stop traffic in a coordinated maintenance window, verify the
backup, apply 008 once, deploy the already reviewed 008-compatible application,
and run read-only health/login checks before restoring traffic. Never put DDL in
`.cpanel.yml` or application bootstrap.

## Non-destructive rollback

The primary rollback is **code-only while retaining migration 008**. Prepare and
review that rollback build before the maintenance window. It must keep:

- migration 008 in the readiness catalog;
- nullable-password-safe repositories and authentication;
- server-controlled session-version validation;
- password recovery/set support for Google-only users; and
- subject-based login for identities already created.

The rollback may remove a faulty new onboarding or presentation path with a
reviewed revert, but it must not restore password-hash session fingerprints,
require non-null hashes, delete Google identities or strand Google-only users.
Unknown or incomplete migrations must continue to fail closed.

A database down-migration is not the normal rollback. Changing
`password_hash` back to `NOT NULL` is prohibited while any Google-only user has a
NULL hash. Dropping confirmation state during an incident can invalidate active
proof links but does not repair authentication. Any later schema contraction
requires a separate reviewed migration, evidence that every user has a valid
recovery method, a fresh backup, and its own maintenance window. Never delete
users or generate placeholder passwords to make a rollback fit.

## Review sign-off

The independent review confirmed checksum/full-schema parity, race and replay
tests, nullable password behavior, session revocation, last-method protection,
metadata-only events and a disposable empty-schema MySQL rehearsal. Before
merge, reviewers must still confirm the restored-backup rehearsal, manual
acceptance results and the prepared 008-compatible rollback commit. Until then:
do not merge, deploy or run migration 008 on production.
