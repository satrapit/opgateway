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
     * currently holds the administrator role. A direct self-join avoids
     * MySQL error 1093 caused by selecting users in an UPDATE subquery.
     * No administrative role grants are created.
     */
    public function approve(int $adminUserId, int $targetUserId): bool
    {
        if ($adminUserId < 1 || $targetUserId < 1 || $adminUserId === $targetUserId) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE users AS target
JOIN users AS admin ON admin.id = ?
JOIN user_roles AS ur ON ur.user_id = admin.id
JOIN roles AS r ON r.id = ur.role_id AND r.role_key = 'admin'
SET target.status = 'active',
    target.approved_at = UTC_TIMESTAMP(6),
    target.updated_at = UTC_TIMESTAMP(6)
WHERE target.id = ?
  AND target.id <> admin.id
  AND target.status = 'pending'
  AND target.email_verified_at IS NOT NULL
  AND admin.status = 'active'
  AND admin.email_verified_at IS NOT NULL
  AND admin.approved_at IS NOT NULL
SQL);
        $statement->execute([$adminUserId, $targetUserId]);
        return $statement->rowCount() === 1;
    }
}
