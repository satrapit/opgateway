<?php

declare(strict_types=1);

namespace OPGateway\Quality;

/**
 * Local-only guard; never rely on Host alone or forwarded request headers.
 * This is in addition to the regular administrator session and role check.
 */
final class LocalQaGate
{
    /** @param array<string,mixed> $server Trusted PHP server metadata */
    public static function allowed(array $server): bool
    {
        if (($server['HTTPS'] ?? null) !== 'on') {
            return false;
        }
        if (strtolower((string) ($server['SERVER_NAME'] ?? '')) !== 'opgateway.test'
            || strtolower((string) ($server['HTTP_HOST'] ?? '')) !== 'opgateway.test') {
            return false;
        }

        return in_array((string) ($server['REMOTE_ADDR'] ?? ''), [
            '127.0.0.1', '::1',
        ], true);
    }
}
