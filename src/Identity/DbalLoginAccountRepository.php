<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Doctrine DBAL equivalent of PdoLoginAccountRepository.
 *
 * Query text and identifiers are static; attacker-controlled values are
 * always bound as parameters. Fails closed on DBAL exceptions.
 */
final readonly class DbalLoginAccountRepository implements LoginAccountRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findEligibleByEmail(string $email): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, password_hash
FROM users
WHERE email = ?
  AND status = 'active'
  AND email_verified_at IS NOT NULL
  AND approved_at IS NOT NULL
LIMIT 1
SQL, [$email], [ParameterType::STRING]);
        if ($row === false) {
            return null;
        }
        return ['id' => (int) $row['id'], 'password_hash' => (string) $row['password_hash']];
    }

    public function isEligible(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        return $this->connection->fetchOne(<<<'SQL'
SELECT 1 FROM users
WHERE id = ?
  AND status = 'active'
  AND email_verified_at IS NOT NULL
  AND approved_at IS NOT NULL
LIMIT 1
SQL, [$userId], [ParameterType::INTEGER]) !== false;
    }
}
