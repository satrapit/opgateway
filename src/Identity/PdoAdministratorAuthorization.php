<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use PDO;

/**
 * Revalidates an administrator's privileges directly from the database.
 * A session user ID alone is never sufficient for administrator access.
 */
final readonly class PdoAdministratorAuthorization
{
    public function __construct(private PDO $pdo)
    {
    }

    public function isAdministrator(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1
FROM users AS u
JOIN user_roles AS ur ON ur.user_id = u.id
JOIN roles AS r ON r.id = ur.role_id
WHERE u.id = ?
  AND u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
  AND r.role_key = 'admin'
LIMIT 1
SQL);
        $statement->execute([$userId]);

        return $statement->fetchColumn() !== false;
    }
}
