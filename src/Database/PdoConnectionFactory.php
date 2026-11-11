<?php

declare(strict_types=1);

namespace OPGateway\Database;

use PDO;

/**
 * The single production MySQL connection factory.
 *
 * A validated host/port is supplied by private configuration. On this
 * hosting provider DB_HOST must be "db" (not "localhost"). The same
 * PDO handle is passed to repositories and transactional services.
 */
final class PdoConnectionFactory
{
    /** @param array<string,string> $config */
    public static function open(array $config): PDO
    {
        $settings = DatabaseConfig::fromEnvironment($config);

        return new PDO(
            $settings->dsn(),
            $settings->username,
            $settings->passwordForConnection(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Match the fixed schema/migration collation even when the
                // MySQL 8 server default is utf8mb4_0900_ai_ci.
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ],
        );
    }
}
