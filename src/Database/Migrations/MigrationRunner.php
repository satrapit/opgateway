<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Reviewed MySQL migration executor. This class is not exposed via HTTP.
 *
 * MySQL DDL can commit implicitly: a failed migration stays marked "running"
 * and may only be resumed after manual investigation, never automatic retry.
 */
final class MigrationRunner
{
    private const LOCK = 'opgateway_schema_migrations';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<Migration> $migrations Fixed, trusted migration catalog.
     * @return list<string> Versions successfully applied by this invocation.
     */
    public function run(array $migrations): array
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('MySQL driver required.');
        }
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([self::LOCK]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Migration lock unavailable.');
        }

        try {
            return $this->runLocked($migrations);
        } finally {
            $unlock = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute([self::LOCK]);
        }
    }

    /** @param list<Migration> $migrations @return list<string> */
    private function runLocked(array $migrations): array
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(128) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $known = [];
        foreach ($this->pdo->query('SELECT version, checksum, status FROM schema_migrations') as $row) {
            $known[$row['version']] = $row;
        }

        $catalog = [];
        $previous = '';
        foreach ($migrations as $migration) {
            $version = $migration->version();
            if (!preg_match('/^[0-9]{12,14}_[a-z0-9_]+$/D', $version)
                || $version <= $previous || isset($catalog[$version])) {
                throw new RuntimeException('Invalid migration catalog.');
            }
            $catalog[$version] = $migration;
            $previous = $version;
        }

        // Validate complete history before applying any additional DDL.
        foreach ($known as $version => $row) {
            if (!isset($catalog[$version])
                || !hash_equals($row['checksum'], self::checksum($catalog[$version]))
                || $row['status'] !== 'completed') {
                throw new RuntimeException('Migration history requires manual review.');
            }
        }

        $applied = [];
        foreach ($catalog as $version => $migration) {
            if (isset($known[$version])) {
                continue;
            }
            if ($migration->statements() === []) {
                throw new RuntimeException('Empty migration refused.');
            }

            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO schema_migrations (version, checksum, status, started_at)
VALUES (?, ?, 'running', UTC_TIMESTAMP(6))
SQL);
            $insert->execute([$version, self::checksum($migration)]);

            try {
                foreach ($migration->statements() as $sql) {
                    if (trim($sql) === '') {
                        throw new RuntimeException('Empty SQL statement refused.');
                    }
                    $this->pdo->exec($sql);
                }
                $update = $this->pdo->prepare(<<<'SQL'
UPDATE schema_migrations SET status = 'completed', completed_at = UTC_TIMESTAMP(6)
WHERE version = ? AND status = 'running'
SQL);
                $update->execute([$version]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Migration ledger finalization failed.');
                }
            } catch (Throwable $exception) {
                throw new RuntimeException('Migration interrupted; manual review required.', 0, $exception);
            }
            $applied[] = $version;
        }
        return $applied;
    }

    /** The checksum must remain unchanged for already deployed migrations. */
    public static function checksum(Migration $migration): string
    {
        return hash('sha256', json_encode($migration->statements(), JSON_THROW_ON_ERROR));
    }
}
