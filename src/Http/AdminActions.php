<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Clients\ClientManagementService;
use OPGateway\Identity\AccountApprovalService;

/**
 * Minimal explicit allowlist of server-side administrator actions.
 *
 * Called ONLY after session revalidation, live administrator role lookup,
 * verified HTTPS, IP allowlisting, bounded form parsing and valid session
 * CSRF. The actor identifier comes from trusted session state, never input.
 * Domain services recheck the administrator role in their SQL mutations.
 */
final readonly class AdminActions
{
    public function __construct(
        private AccountApprovalService $accounts,
        private ClientManagementService $clients,
    ) {
    }

    /** @param array<string,mixed> $payload
     *  @return array{status:int,body:array<string,string>}
     */
    public function dispatch(int $adminUserId, array $payload): array
    {
        if ($adminUserId < 1) {
            return self::result(403, 'forbidden');
        }

        $action = $payload['action'] ?? null;
        if (!is_string($action)) {
            return self::result(400, 'invalid_request');
        }

        switch ($action) {
            case 'approve_user':
                if (!BoundedJson::fields($payload, ['action', 'user_id'])) {
                    return self::result(400, 'invalid_request');
                }
                $id = BoundedJson::positiveId($payload['user_id']);
                $allowed = $id !== null && $this->accounts->approve($adminUserId, $id);
                break;

            case 'activate_client':
                if (!BoundedJson::fields($payload, ['action', 'client_id'])) {
                    return self::result(400, 'invalid_request');
                }
                $id = BoundedJson::positiveId($payload['client_id']);
                $allowed = $id !== null && $this->clients->activate($adminUserId, $id);
                break;

            case 'approve_ip':
                if (!BoundedJson::fields($payload, ['action', 'ip_entry_id'])) {
                    return self::result(400, 'invalid_request');
                }
                $id = BoundedJson::positiveId($payload['ip_entry_id']);
                $allowed = $id !== null && $this->clients->approveIp($adminUserId, $id);
                break;

            default:
                return self::result(404, 'unknown_action');
        }

        // Do not disclose whether a resource exists or was ineligible.
        return $allowed ? self::result(200, 'ok') : self::result(403, 'action_denied');
    }

    /** @return array{status:int,body:array<string,string>} */
    private static function result(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['status' => $code]];
    }
}
