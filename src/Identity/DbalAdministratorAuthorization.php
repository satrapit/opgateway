<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * DBAL-backed authorization rechecks active/approved/verified state and
 * admin role on every protected request. A session ID alone is not enough.
 */
final readonly class DbalAdministratorAuthorization
{
    public function __construct(private Connection $connection)
    {
    }

    public function isAdministrator(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        return $this->connection->fetchOne(<<<'SQL'
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
SQL, [$userId], [ParameterType::INTEGER]) !== false;
    }
}
