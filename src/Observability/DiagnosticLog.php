<?php

declare(strict_types=1);

namespace OPGateway\Observability;

use PDOException;
use Throwable;

/**
 * Write structured, metadata-only JSONL diagnostics outside Document Root.
 *
 * Event names and context keys are allowlisted by code. Never log exception
 * messages, SQL strings, credentials, raw IPs, headers, bodies, or stack
 * traces. Correlate every record with the public X-Request-ID response header.
 */
final class DiagnosticLog
{
    private static ?string $filename = null;
    private static ?string $requestId = null;

    /** Only the following context values can enter a diagnostic record. */
    private const SAFE_CONTEXT_VALUES = [
        'driver' => ['mysql', 'unavailable'],
        'schema' => ['ready', 'incomplete', 'invalid'],
        'operation' => ['connect', 'migration_check', 'identity_check', 'session', 'provision'],
    ];

    public static function initialize(string $filename, string $requestId): void
    {
        self::$filename = $filename;
        self::$requestId = preg_match('/^[0-9a-f]{32}$/D', $requestId) === 1
            ? $requestId : null;
    }

    /**
     * @param array<string,string> $context Only values in SAFE_CONTEXT_VALUES.
     */
    public static function record(
        string $event,
        int $status,
        ?Throwable $exception = null,
        array $context = [],
    ): void {
        if (self::$filename === null || self::$requestId === null
            || preg_match('/^[a-z][a-z0-9_.]{2,79}$/D', $event) !== 1
            || $status < 100 || $status > 599) {
            return;
        }

        $category = match (true) {
            $exception instanceof PDOException => 'database',
            $exception instanceof \RuntimeException => 'runtime',
            $exception instanceof \InvalidArgumentException => 'validation',
            $exception instanceof \Error => 'programming',
            $exception === null => 'none',
            default => 'unexpected',
        };

        $entry = [
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id' => self::$requestId,
            'event' => $event,
            'http_status' => $status,
            'error_category' => $category,
        ];

        if ($exception instanceof PDOException) {
            $entry['database'] = DatabaseFailure::classify($exception);
        }

        $approvedContext = [];
        foreach ($context as $key => $value) {
            if (isset(self::SAFE_CONTEXT_VALUES[$key])
                && in_array($value, self::SAFE_CONTEXT_VALUES[$key], true)) {
                $approvedContext[$key] = $value;
            }
        }
        if ($approvedContext !== []) {
            $entry['context'] = $approvedContext;
        }

        try {
            $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (Throwable) {
            error_log('OPGateway diagnostic encoding unavailable.');
            return;
        }

        $directory = dirname(self::$filename);
        if (!is_dir($directory) || is_link($directory) || is_link(self::$filename)) {
            error_log('OPGateway diagnostic log directory unavailable.');
            return;
        }

        $handle = @fopen(self::$filename, 'ab');
        if ($handle === false) {
            error_log('OPGateway diagnostic log write unavailable.');
            return;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                error_log('OPGateway diagnostic log lock unavailable.');
                return;
            }
            if (@fwrite($handle, $line) !== strlen($line)) {
                error_log('OPGateway diagnostic log write incomplete.');
            }
            @fflush($handle);
            @flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}
