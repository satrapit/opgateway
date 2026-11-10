<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Fixed server-owned destinations after successful administrator mutations.
 * Ignore client Origin, Referer and arbitrary return URLs.
 */
final class AdminMutationRedirect
{
    public static function forAction(string $action): string
    {
        return match ($action) {
            'approve_user' => '/admin/users',
            'create_client' => '/admin/owners',
            'activate_client', 'disable_client', 'request_ip' => '/admin/clients',
            'approve_ip' => '/admin/ips/pending',
            'revoke_ip' => '/admin/ips/approved',
            default => '/admin',
        };
    }
}
