<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

use LogicException;

/**
 * Explicit allowlist of immutable schema changes in ascending order.
 *
 * This catalog performs no I/O; deployments never auto-apply migrations.
 */
final class MigrationCatalog
{
    /** @return list<Migration> */
    public static function all(): array
    {
        $migrations = [
            new CreateIdentityTables(),
            new CreateEmailVerificationTokens(),
            new CreateLoginSecurityTables(),
            new CreateGatewayIntegrationTables(),
            new UnifySettingsStorage(),
        ];

        $versions = array_map(static fn (Migration $migration): string => $migration->version(), $migrations);
        $sorted = $versions;
        sort($sorted, SORT_STRING);

        if (count($versions) !== count(array_unique($versions)) || $sorted !== $versions) {
            throw new LogicException('Invalid migration catalog order or duplicate version.');
        }

        return $migrations;
    }
}
