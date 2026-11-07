<?php

declare(strict_types=1);

namespace OPGateway\Database;

/**
 * Optional read-only MySQLi connection diagnostic for PDO failures.
 *
 * The caller must already have verified setup HTTPS and exact source IP.
 * No credentials or exception text are logged or returned to clients.
 * The probe never changes application connection mode or database state.
 */
final class MysqliConnectionProbe
{
    /**
     * @param array<string,string> $config
     * @return 'driver_unavailable'|'invalid_config'|'connected'|'authentication_rejected'|'access_denied'|'unknown_database'|'connection_failure'|'connection_limit'|'authentication_plugin'|'unclassified'
     */
    public static function inspect(array $config): string
    {
        if (!extension_loaded('mysqli')) {
            return 'driver_unavailable';
        }

        $host = $config['DB_HOST'] ?? '';
        $port = $config['DB_PORT'] ?? '';
        $dbname = $config['DB_NAME'] ?? '';
        $user = $config['DB_USER'] ?? '';
        if ($host === '' || !ctype_digit($port) || (int) $port < 1
            || (int) $port > 65535 || $dbname === '' || $user === '') {
            return 'invalid_config';
        }

        $driver = new \mysqli_driver();
        $originalMode = $driver->report_mode;
        mysqli_report(MYSQLI_REPORT_OFF);

        $connection = null;
        try {
            $connection = @mysqli_init();
            if (!$connection instanceof \mysqli) {
                return 'unclassified';
            }

            try {
                $ok = @mysqli_real_connect(
                    $connection,
                    $host,
                    $user,
                    $config['DB_PASSWORD'] ?? '',
                    $dbname,
                    (int) $port,
                );
                if (!$ok) {
                    // On some mysqlnd builds the global errno is zero even
                    // when this particular connection reports a failure.
                    $errno = (int) $connection->connect_errno;
                    return self::reason($errno !== 0 ? $errno : (int) mysqli_connect_errno());
                }

                $result = @$connection->query('SELECT 1');
                if ($result === false) {
                    return self::reason((int) $connection->errno);
                }
                $result->free();
                return 'connected';
            } finally {
                try {
                    @$connection->close();
                } catch (\Throwable) {
                    // Closing a failed connection must never mask diagnostics.
                }
            }
        } catch (\mysqli_sql_exception $exception) {
            // The numeric driver code is safe to classify; its free-form
            // message can contain host/user data and must never be logged.
            return self::reason((int) $exception->getCode());
        } catch (\Throwable) {
            return 'unclassified';
        } finally {
            mysqli_report($originalMode);
        }
    }

    public static function reason(int $code): string
    {
        return match ($code) {
            1045 => 'authentication_rejected',
            1044, 1142, 1143, 1227 => 'access_denied',
            1049 => 'unknown_database',
            1040, 1203 => 'connection_limit',
            2054 => 'authentication_plugin',
            2002, 2003, 2005, 2006, 2013 => 'connection_failure',
            default => 'unclassified',
        };
    }
}
