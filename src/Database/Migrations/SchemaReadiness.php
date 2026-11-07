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
            if (!isset($versions[$version])
                || !is_string($row['checksum'])
                || !hash_equals(MigrationRunner::checksum($versions[$version]), $row['checksum'])
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
