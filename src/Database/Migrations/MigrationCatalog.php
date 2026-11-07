<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

use LogicException;

/**
 * Explicit allowlist of migration definitions, ordered by version.
 * Nothing in this class performs database I/O.
 */
final class MigrationCatalog
{
    /** @return list<Migration> */
    public static function all(): array
    {
        $migrations = [new CreateIdentityTables()];
        $versions = array_map(static fn (Migration $migration): string => $migration->version(), $migrations);
        $sorted = $versions;
        sort($sorted, SORT_STRING);

        if (count($versions) !== count(array_unique($versions)) || $sorted !== $versions) {
            throw new LogicException('Invalid migration catalog order or duplicate version.');
        }

        return $migrations;
    }
}
