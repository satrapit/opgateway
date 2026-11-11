<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

use PDO;
use RuntimeException;

/**
 * Read-only production schema preflight.
 *
 * It never runs DDL, repairs migration records, or automatically updates
 * databases. Missing/incomplete/mismatched history always denies readiness.
 */
final readonly class SchemaReadiness
{
    /**
     * A narrowly scoped rollback bridge: alpha.4.1 may run against a database
     * already upgraded to Google identity migration 007. Keep its exact
     * checksum pinned; unknown, incomplete or modified migrations still fail.
     *
     * This does not apply migration 007 or enable Google sign-in.
     */
    private const OPTIONAL_NEWER_MIGRATIONS = [
        '202611110001_create_google_identities'
            => '668039504a182a0dbb6c178897d901fbd5a1489b7bee31b424e1c70b1d2058c5',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<Migration> $catalog
     * @return array{ready:bool,completed:list<string>,missing:list<string>}
     */
    public function inspect(array $catalog): array
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('MySQL is required for schema inspection.');
        }

        $found = $this->pdo->query(<<<'SQL'
SELECT version, checksum, status FROM schema_migrations
SQL)->fetchAll(PDO::FETCH_ASSOC);

        $known = [];
        foreach ($found as $row) {
            if (!is_string($row['version']) || isset($known[$row['version']])) {
                throw new RuntimeException('Invalid migration history.');
            }
            $known[$row['version']] = $row;
        }

        $versions = [];
        foreach ($catalog as $migration) {
            $version = $migration->version();
            if (isset($versions[$version])) {
                throw new RuntimeException('Duplicate migration definition.');
            }
            $versions[$version] = $migration;
        }

        foreach ($known as $version => $row) {
            // A known later, completed migration may remain after reverting
            // application code. This is an exact-version/checksum exception,
            // never a general allowance for unknown schema modifications.
            $expectedChecksum = isset($versions[$version])
                ? MigrationRunner::checksum($versions[$version])
                : (self::OPTIONAL_NEWER_MIGRATIONS[$version] ?? null);
            if (!is_string($expectedChecksum)
                || !is_string($row['checksum'])
                || !hash_equals($expectedChecksum, $row['checksum'])
                || $row['status'] !== 'completed') {
                throw new RuntimeException('Migration history requires manual review.');
            }
        }

        $completed = [];
        $missing = [];
        foreach ($versions as $version => $migration) {
            if (isset($known[$version])) {
                $completed[] = $version;
            } else {
                $missing[] = $version;
            }
        }

        // Reject a skipped prerequisite even if the final version exists.
        if ($completed !== array_slice(array_keys($versions), 0, count($completed))) {
            throw new RuntimeException('Non-sequential migration history.');
        }

        return ['ready' => $missing === [], 'completed' => $completed, 'missing' => $missing];
    }
}
