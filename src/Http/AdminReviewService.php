<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Clients\AdminClientProvisioningService;
use OPGateway\Security\AdminActionLimiter;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use Throwable;

/**
 * Transactional executor for three explicitly approved admin mutations.
 *
 * HTTP authorization is enforced by PrivateBrowserAdapter and the underlying
 * domain SQL revalidates the administrator and target state. Throttling is
 * reserved before the transaction; audit insertion and the state change are
 * committed atomically. A failed audit insert rolls back the mutation.
 */
final readonly class AdminReviewService
{
    public function __construct(
        private PDO $pdo,
        private AdminActions $actions,
        private AdminActionLimiter $limiter,
        private SecurityEventRecorder $events,
        private ?AdminClientProvisioningService $provisioning = null,
    ) {
    }

    /**
     * Provision a disabled API client or request a disabled IP entry.
     * Action attempt counts are reserved before SQL; a failed audit insert
     * rolls back the new client/IP record in the same transaction.
     *
     * @return array{status:int,code:string}
     */
    public function provision(
        int $administratorId,
        string $sourceIp,
        string $requestId,
        string $action,
        int $targetId,
        string $value,
    ): array {
        if ($this->provisioning === null || $administratorId < 1 || $targetId < 1) {
            return ['status' => 403, 'code' => 'action_denied'];
        }

        if ($action === 'create_client') {
            $value = trim($value);
            if ($value === '' || strlen($value) > 128
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return ['status' => 400, 'code' => 'invalid_request'];
            }
        } elseif ($action === 'request_ip') {
            if ($value === '' || strlen($value) > 45
                || trim($value) !== $value || @inet_pton($value) === false) {
                return ['status' => 400, 'code' => 'invalid_request'];
            }
        } else {
            return ['status' => 400, 'code' => 'invalid_request'];
        }

        if (!$this->limiter->allow($administratorId, $sourceIp)) {
            $this->events->record('admin_review_throttled', $requestId);
            return ['status' => 429, 'code' => 'too_many_requests'];
        }

        $this->pdo->beginTransaction();
        try {
            $allowed = $action === 'create_client'
                ? $this->provisioning->createClient($administratorId, $targetId, $value)
                : $this->provisioning->requestIp($administratorId, $targetId, $value);
            $this->events->record(
                'admin_' . $action . ($allowed ? '_allowed' : '_denied'),
                $requestId,
            );
            $this->pdo->commit();

            return $allowed
                ? ['status' => 200, 'code' => 'ok']
                : ['status' => 403, 'code' => 'action_denied'];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{status:int,code:string} */
    public function execute(
        int $administratorId,
        string $sourceIp,
        string $requestId,
        string $action,
        int $targetId,
    ): array {
        $identifierKey = match ($action) {
            'approve_user' => 'user_id',
            'activate_client' => 'client_id',
            'approve_ip' => 'ip_entry_id',
            'disable_client' => 'client_id',
            'revoke_ip' => 'ip_entry_id',
            default => null,
        };

        if ($identifierKey === null || $targetId < 1 || $administratorId < 1) {
            return ['status' => 400, 'code' => 'invalid_request'];
        }

        if (!$this->limiter->allow($administratorId, $sourceIp)) {
            $this->events->record('admin_review_throttled', $requestId);
            return ['status' => 429, 'code' => 'too_many_requests'];
        }

        $this->pdo->beginTransaction();
        try {
            // Domain services check the live admin/owner/target state in SQL.
            $result = match ($action) {
                'disable_client' => ['status' => $this->provisioning?->disableClient(
                    $administratorId, $targetId,
                ) === true ? 200 : 403],
                'revoke_ip' => ['status' => $this->provisioning?->revokeIp(
                    $administratorId, $targetId,
                ) === true ? 200 : 403],
                default => $this->actions->dispatch(
                    $administratorId, ['action' => $action, $identifierKey => $targetId],
                ),
            };
            $approved = $result['status'] === 200;
            $this->events->record(
                'admin_' . $action . ($approved ? '_allowed' : '_denied'),
                $requestId,
            );
            $this->pdo->commit();

            return $approved
                ? ['status' => 200, 'code' => 'ok']
                : ['status' => 403, 'code' => 'action_denied'];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
