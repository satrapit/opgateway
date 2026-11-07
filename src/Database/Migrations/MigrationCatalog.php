<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

use LogicException;

/**
 * Explicit, ordered allowlist of reviewed migrations.
 * No database I/O occurs when reading the catalog.
 */
final class MigrationCatalog
{
    /** @return list<Migration> */
    public static function all(): array
    {
        $migrations = [new CreateIdentityTables(), new CreateEmailVerificationTokens()];
        $versions = array_map(static fn (Migration $migration): string => $migration->version(), $migrations);
        $sorted = $versions;
        sort($sorted, SORT_STRING);

        if (count($versions) !== count(array_unique($versions)) || $sorted !== $versions) {
            throw new LogicException('Invalid migration catalog order or duplicate version.');
        }

        return $migrations;
    }
}
