<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

/**
 * DBAL-backed user registration persistence with parameterized inserts.
 * Validation is provided by the existing registration service.
 */
final readonly class DbalUserRepository implements UserRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function insertPending(string $email, string $passwordHash): int
    {
        $affected = $this->connection->executeStatement(<<<'SQL'
INSERT INTO users (email, password_hash, status, created_at, updated_at)
VALUES (?, ?, 'pending', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL, [$email, $passwordHash], [ParameterType::STRING, ParameterType::STRING]);
        if ($affected !== 1) {
            throw new RuntimeException('Unable to create pending user.');
        }

        $id = (int) $this->connection->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Unable to read created user ID.');
        }
        return $id;
    }
}
