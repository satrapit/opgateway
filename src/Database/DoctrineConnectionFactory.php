<?php

declare(strict_types=1);

namespace OPGateway\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use RuntimeException;

/**
 * Doctrine DBAL connection factory for PHP 8.3 shared hosting.
 *
 * Uses an explicit driver allowlist; never derives connection URLs from
 * user-controlled input. Connections are eagerly checked before use.
 * This class is an opt-in migration target; existing PDO flows remain
 * unchanged until the application has a reproducible vendor deployment.
 */
final class DoctrineConnectionFactory
{
    /** @param array<string,string> $config */
    public static function open(array $config): Connection
    {
        if (!class_exists(DriverManager::class)) {
            throw new RuntimeException('Doctrine DBAL dependency unavailable.');
        }

        $driver = $config['DB_DRIVER'] ?? 'pdo_mysql';
        if (!in_array($driver, ['pdo_mysql', 'mysqli'], true)) {
            throw new RuntimeException('Unsupported database driver.');
        }

        $host = $config['DB_HOST'] ?? '';
        $port = $config['DB_PORT'] ?? '';
        $dbname = $config['DB_NAME'] ?? '';
        $user = $config['DB_USER'] ?? '';
        if ($host === '' || str_contains($host, ';')
            || $dbname === '' || preg_match('/^[A-Za-z0-9_]+$/D', $dbname) !== 1
            || $user === '' || preg_match('/^[A-Za-z0-9_]+$/D', $user) !== 1
            || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Database configuration unavailable.');
        }

        // An explicit socket is an advanced operator override, not inferred
        // from HTTP or untrusted config values; prohibit control characters.
        $params = [
            'driver' => $driver,
            'host' => $host,
            'port' => (int) $port,
            'dbname' => $dbname,
            'user' => $user,
            'password' => $config['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
        ];
        $socket = $config['DB_UNIX_SOCKET'] ?? '';
        if ($socket !== '') {
            if ($socket[0] !== '/' || strlen($socket) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $socket) === 1) {
                throw new RuntimeException('Invalid database socket path.');
            }
            $params['unix_socket'] = $socket;
        }

        $connection = DriverManager::getConnection($params);
        // DriverManager is normally lazy; force a real connection so errors
        // are attributed to this stage instead of first application query.
        $connection->connect();
        return $connection;
    }
}
