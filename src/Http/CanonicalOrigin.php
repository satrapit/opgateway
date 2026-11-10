<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Use the server's configured HTTPS virtual-host name, never an arbitrary
 * client Host or X-Forwarded-* value, as the canonical URL for email links.
 * Requires SERVER_NAME to be configured to the exact application hostname.
 */
final class CanonicalOrigin
{
    /** @param array<string,mixed> $server */
    public static function fromServer(array $server): ?string
    {
        $name = strtolower((string) ($server['SERVER_NAME'] ?? ''));
        $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
        if (($server['HTTPS'] ?? '') !== 'on'
            || preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $name) !== 1
            || !str_contains($name, '.') || str_contains($name, '..')
            || !hash_equals($name, $host)) {
            return null;
        }
        return 'https://' . $name;
    }
}
