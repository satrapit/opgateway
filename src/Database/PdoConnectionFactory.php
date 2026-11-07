<?php

declare(strict_types=1);

namespace OPGateway\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Creates an explicitly configured MySQL connection on demand.
 *
 * Database failures must be reported to HTTP clients only as generic errors.
 */
final class PdoConnectionFactory
{
    /** @throws RuntimeException If the driver or connection is unavailable. */
    public function connect(DatabaseConfig $config): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Required database driver is unavailable.');
        }

        try {
            return new PDO($config->dsn(), $config->username, $config->passwordForConnection(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $exception) {
            // Preserve the cause only for trusted exception handling; never send
            // chained PDO details to HTTP responses or structured logs.
            throw new RuntimeException('Database connection unavailable.', 0, $exception);
        }
    }
}
