<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use PDO;

/**
 * Requires an already-authorized, active and verified administrator.
 * Granting the first administrator is deliberately out of scope.
 */
final readonly class AccountApprovalService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Atomically approve a verified pending account only when the actor
     * currently holds the administrator role. Records no assumed role grants.
     */
    public function approve(int $adminUserId, int $targetUserId): bool
    {
        if ($adminUserId < 1 || $targetUserId < 1 || $adminUserId === $targetUserId) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE users AS target
SET target.status = 'active',
    target.approved_at = UTC_TIMESTAMP(6),
    target.updated_at = UTC_TIMESTAMP(6)
WHERE target.id = ?
  AND target.status = 'pending'
  AND target.email_verified_at IS NOT NULL
  AND EXISTS (
    SELECT 1 FROM users AS admin
    JOIN user_roles AS ur ON ur.user_id = admin.id
    JOIN roles AS r ON r.id = ur.role_id
    WHERE admin.id = ?
      AND admin.status = 'active'
      AND admin.email_verified_at IS NOT NULL
      AND r.role_key = 'admin'
  )
SQL);
        $statement->execute([$targetUserId, $adminUserId]);
        return $statement->rowCount() === 1;
    }
}
