<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use PDO;

/**
 * Persists only normalized email, password hash and pending status.
 * Never accepts SQL fragments or uses concatenated user input.
 */
final readonly class PdoUserRepository implements UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function insertPending(string $email, string $passwordHash): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO users (email, password_hash, status, created_at, updated_at)
VALUES (?, ?, 'pending', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
        $statement->execute([$email, $passwordHash]);

        return (int) $this->pdo->lastInsertId();
    }
}
