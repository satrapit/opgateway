<?php

declare(strict_types=1);

namespace OPGateway\Database;

use Medoo\Medoo;
use PDO;
use RuntimeException;

/**
 * The application's single database connection provider.
 *
 * Medoo uses PDO internally. Reuse that *same* PDO handle for existing
 * transactions and security-sensitive prepared statements. No second
 * driver, Doctrine DBAL connection or silent fallback is permitted.
 *
 * Composer dependencies must be installed outside shared hosting and
 * deployed into the private repository's vendor directory.
 */
final class MedooConnectionFactory
{
    /** @param array<string,string> $config */
    public static function open(array $config): Medoo
    {
        $vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($vendorAutoload)) {
            throw new RuntimeException('Database dependency unavailable.');
        }
        require_once $vendorAutoload;
        if (!class_exists(Medoo::class)) {
            throw new RuntimeException('Database dependency unavailable.');
        }

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

        $options = [
            'type' => 'mysql',
            'host' => $host,
            'port' => (int) $port,
            'database' => $name,
            'username' => $user,
            'password' => $config['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'error' => PDO::ERRMODE_EXCEPTION,
        ];
        $socket = $config['DB_UNIX_SOCKET'] ?? '';
        if ($socket !== '') {
            if ($socket[0] !== '/' || strlen($socket) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $socket) === 1) {
                throw new RuntimeException('Invalid database socket path.');
            }
            // Medoo's socket option replaces host/port transport options.
            unset($options['host'], $options['port']);
            $options['socket'] = $socket;
        }
        return new Medoo($options);
    }

    /** Existing PDO repositories use the PDO managed by Medoo. */
    public static function pdo(array $config): PDO
    {
        $database = self::open($config);
        $pdo = $database->pdo;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection unavailable.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }
}
