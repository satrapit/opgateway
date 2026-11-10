<?php

declare(strict_types=1);

namespace OPGateway\Provider;

/**
 * Fixed-host REST facade. No arbitrary URL, encoded slash, dot segments,
 * user-controlled host, provider token issuance or unsupported root paths.
 *
 * Paid or state-changing operations are unavailable pending a narrowly
 * scoped operation authorization design. Availability checks are read-only.
 */
final class OpenproviderRoutePolicy
{
    private const ROOTS = [
        'domains', 'dns', 'ssl', 'customers', 'contacts', 'resellers',
        'invoices', 'payments', 'licenses', 'easydmarcs', 'emails',
        'products', 'nameservers',
    ];

    public static function allowed(string $method, string $path): bool
    {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || strlen($path) > 255
            || preg_match('~^/v1/([a-z][a-z0-9-]{0,31})(?:/[a-zA-Z0-9_-]+){0,6}/?$~D', $path, $match) !== 1
            || !in_array($match[1], self::ROOTS, true)) {
            return false;
        }

        if ($method === 'GET') {
            return true;
        }
        // Availability checks are non-mutating despite using POST.
        if ($method === 'POST' && rtrim($path, '/') === '/v1/domains/check') {
            return true;
        }
        return false;
    }

    /** Query is strictly opaque data, not another URL or a redirect. */
    public static function validQuery(string $rawQuery): bool
    {
        if ($rawQuery === '') {
            return true;
        }
        if (strlen($rawQuery) > 1024 || preg_match('/[%\\\\#\x00-\x1f]/', $rawQuery)) {
            return false;
        }
        return preg_match(
            '/^(?:[a-z][a-z0-9_]{0,39}=[a-zA-Z0-9._~-]{0,128})(?:&[a-z][a-z0-9_]{0,39}=[a-zA-Z0-9._~-]{0,128}){0,15}$/D',
            $rawQuery,
        ) === 1;
    }
}
