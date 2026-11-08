<?php

declare(strict_types=1);

namespace OPGateway\Http;

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
    ) {
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
            $result = $this->actions->dispatch(
                $administratorId, ['action' => $action, $identifierKey => $targetId],
            );
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
