<?php

declare(strict_types=1);

namespace OPGateway\Observability;

use Throwable;

/**
 * Metadata-only JSON Lines audit/diagnostic sink.
 *
 * No exception messages, stack traces, source paths, client addresses,
 * request bodies, headers, credentials or environment values are recorded.
 * The event code is internal and fixed. Each response carries the same
 * random request identifier as its corresponding diagnostic entries.
 *
 * The log must reside outside Document Root in an operator-created private
 * directory. If writing is unavailable, a fixed, non-sensitive marker is
 * passed to PHP's configured error_log; never echo log failures to clients.
 */
final class DiagnosticLog
{
    private static ?string $filename = null;
    private static ?string $requestId = null;

    public static function initialize(string $filename, string $requestId): void
    {
        self::$filename = $filename;
        self::$requestId = preg_match('/^[0-9a-f]{32}$/D', $requestId) === 1
            ? $requestId : null;
    }

    /** Record only approved, non-user-controlled metadata. */
    public static function record(string $event, int $status, ?Throwable $exception = null): void
    {
        if (self::$filename === null || self::$requestId === null
            || preg_match('/^[a-z][a-z0-9_.]{2,79}$/D', $event) !== 1
            || $status < 100 || $status > 599) {
            return;
        }

        $category = match (true) {
            $exception instanceof \PDOException => 'database',
            $exception instanceof \RuntimeException => 'runtime',
            $exception instanceof \InvalidArgumentException => 'validation',
            $exception instanceof \Error => 'programming',
            $exception === null => 'none',
            default => 'unexpected',
        };
        $line = json_encode([
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id' => self::$requestId,
            'event' => $event,
            'http_status' => $status,
            'error_category' => $category,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";

        $directory = dirname(self::$filename);
        if (!is_dir($directory) || is_link($directory)
            || is_link(self::$filename)) {
            error_log('OPGateway diagnostic log directory unavailable.');
            return;
        }

        // Keep this directory isolated from publicly accessible content.
        $handle = @fopen(self::$filename, 'ab');
        if ($handle === false) {
            error_log('OPGateway diagnostic log write unavailable.');
            return;
        }

        try {
            if (@flock($handle, LOCK_EX)) {
                @fwrite($handle, $line);
                @fflush($handle);
                @flock($handle, LOCK_UN);
            } else {
                error_log('OPGateway diagnostic log lock unavailable.');
            }
        } finally {
            fclose($handle);
        }
    }
}
