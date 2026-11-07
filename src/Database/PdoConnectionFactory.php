<?php

declare(strict_types=1);

namespace OPGateway\Database;

use PDO;
use RuntimeException;

/**
 * Opens a MySQL connection using private configuration.
 *
 * The connection is not constructed before a request has passed its
 * feature gate and transport/source restrictions. No credentials may be
 * included in exceptions returned to HTTP clients.
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
            || $name === '' || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)
            || $user === '' || !preg_match('/^[a-zA-Z0-9_]+$/D', $user)
            || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Database configuration unavailable.');
        }

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
            $user,
            $config['DB_PASSWORD'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }
}
