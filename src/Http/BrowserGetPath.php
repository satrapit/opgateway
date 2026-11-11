<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Tolerate a trailing slash only on the known GET browser pages.
 * It must never rewrite unsafe methods, API endpoints or unknown routes.
 * Keeps QUERY_STRING unchanged (notably reset-password tokens).
 */
final class BrowserGetPath
{
    public static function normalize(string $method, string $path): string
    {
        if ($method !== 'GET' || $path === '/' || !str_ends_with($path, '/')) {
            return $path;
        }

        $canonical = substr($path, 0, -1);
        return in_array($canonical, [
            '/admin', '/admin/login', '/admin/users', '/admin/owners',
            '/admin/clients', '/admin/ips/pending', '/admin/ips/approved',
            '/admin/settings', '/admin/settings/login',
            '/admin/settings/login/google', '/admin/settings/login/captcha',
            '/admin/settings/smtp', '/admin/settings/google',
            '/account', '/account/login', '/account/register',
            '/account/verify', '/account/forgot-password', '/account/reset-password',
            '/account/provider', '/account/clients', '/account/keys', '/account/ips',
            '/account/google', '/account/google/start', '/account/google/callback',
        ], true) ? $canonical : $path;
    }
}
