<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Fixed server-owned Post/Redirect/Get destinations after successful actions.
 *
 * Client-provided Origin/Referer and arbitrary 'return_to' form fields are
 * intentionally ignored. The historical legacy dashboard stays unchanged.
 * Only called AFTER the original mutation authorization succeeds.
 */
final class AdminMutationRedirect
{
    public static function forAction(string $action, bool $twigEnabled): string
    {
        if (!$twigEnabled) {
            return '/admin';
        }

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
