<?php

declare(strict_types=1);

namespace OPGateway\Database;

use mysqli;
use mysqli_driver;
use mysqli_result;
use mysqli_sql_exception;
use Throwable;

/**
 * Operator-gated, read-only native MySQLi connection test.
 *
 * Only finite status/stage names and numeric errno are returned. Never
 * expose the exception message, user, host, SQL, password or socket path.
 */
final class MysqliConnectionProbe
{
    /** @param array<string,string> $config */
    public static function inspect(array $config): string
    {
        return self::inspectDetailed($config)['reason'];
    }

    /**
     * @param array<string,string> $config
     * @return array{reason:string,stage:string,mysql_errno:int|null}
     */
    public static function inspectDetailed(array $config): array
    {
        if (!extension_loaded('mysqli')) {
            return self::result('driver_unavailable', 'driver', null);
        }

        $host = $config['DB_HOST'] ?? '';
        $port = $config['DB_PORT'] ?? '';
        $dbname = $config['DB_NAME'] ?? '';
        $user = $config['DB_USER'] ?? '';
        if ($host === '' || str_contains($host, ';') || !ctype_digit($port)
            || (int) $port < 1 || (int) $port > 65535
            || preg_match('/^[A-Za-z0-9_]+$/D', $dbname) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/D', $user) !== 1) {
            return self::result('invalid_config', 'config', null);
        }

        $driver = new mysqli_driver();
        $originalMode = $driver->report_mode;
        mysqli_report(MYSQLI_REPORT_OFF);
        $connection = null;
        $stage = 'init';

        try {
            $connection = @mysqli_init();
            if (!$connection instanceof mysqli) {
                return self::result('unclassified', $stage, null);
            }

            $stage = 'connect';
            $ok = @mysqli_real_connect(
                $connection,
                $host,
                $user,
                $config['DB_PASSWORD'] ?? '',
                $dbname,
                (int) $port,
            );
            if (!$ok) {
                $errno = (int) $connection->connect_errno;
                if ($errno === 0) {
                    $errno = (int) mysqli_connect_errno();
                }
                return self::result(self::reason($errno), $stage, $errno);
            }

            $stage = 'query';
            $query = @$connection->query('SELECT 1');
            if (!$query instanceof mysqli_result) {
                $errno = (int) $connection->errno;
                return self::result(self::reason($errno), $stage, $errno);
            }
            $query->free();
            return self::result('connected', $stage, null);
        } catch (mysqli_sql_exception $exception) {
            $errno = (int) $exception->getCode();
            return self::result(self::reason($errno), $stage, $errno);
        } catch (Throwable) {
            return self::result('unclassified', $stage, null);
        } finally {
            if ($connection instanceof mysqli) {
                try {
                    @$connection->close();
                } catch (Throwable) {
                    // Cleanup is best-effort; no raw error escapes.
                }
            }
            mysqli_report($originalMode);
        }
    }

    /** Whitelist the stage and reason; permit only numeric MySQL errno. */
    private static function result(string $reason, string $stage, ?int $errno): array
    {
        return [
            'reason' => $reason,
            'stage' => in_array($stage, ['driver', 'config', 'init', 'connect', 'query'], true)
                ? $stage : 'init',
            'mysql_errno' => $errno !== null && $errno >= 1 && $errno <= 65535
                ? $errno : null,
        ];
    }

    public static function reason(int $code): string
    {
        return match ($code) {
            1045, 1698 => 'authentication_rejected',
            1044, 1142, 1143, 1227 => 'access_denied',
            1049 => 'unknown_database',
            1040, 1203 => 'connection_limit',
            2054 => 'authentication_plugin',
            2002, 2003, 2005, 2006, 2013 => 'connection_failure',
            default => 'unclassified',
        };
    }
}
