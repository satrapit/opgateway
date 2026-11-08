<?php

declare(strict_types=1);

namespace OPGateway\Database;

use PDO;
use RuntimeException;

/**
 * Single native PDO/MySQL connection provider for all application SQL.
 *
 * Domain repositories receive this PDO handle, use prepared statements
 * with bound values, and manage transactional operations explicitly.
 * No ORM, Medoo, Doctrine, MySQLi or connection fallbacks are used.
 */
final class PdoConnectionFactory
{
    /** @param array<string,string> $config */
    public static function open(array $config): PDO
    {
        $host = $config['DB_HOST'] ?? '';
        $port = $config['DB_PORT'] ?? '';
        $name = $config['DB_NAME'] ?? '';
        $user = $config['DB_USER'] ?? '';

        if ($host === '' || str_contains($host, ';')
            || preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/D', $user) !== 1
            || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Database configuration unavailable.');
        }

        $socket = $config['DB_UNIX_SOCKET'] ?? '';
        if ($socket !== '') {
            if ($socket[0] !== '/' || strlen($socket) > 255
                || preg_match('/[\x00-\x1f\x7f;]/', $socket) === 1) {
                throw new RuntimeException('Database configuration unavailable.');
            }
            $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $name . ';charset=utf8mb4';
        } else {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
        }

        return new PDO($dsn, $user, $config['DB_PASSWORD'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
