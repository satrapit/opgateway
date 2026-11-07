<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use PDO;
use RuntimeException;
use Throwable;

/**
 * First administrator creation, callable only by a future authenticated
 * operator installer, never directly from an unauthenticated HTTP route.
 *
 * Strong bootstrap proof + an EMPTY users table are required. A MySQL named
 * lock prevents concurrent installer invocations; inserts are transactional.
 * The setup token is never stored in the database or logged.
 */
final readonly class AdministratorBootstrap
{
    private const LOCK_NAME = 'opgateway_admin_bootstrap';

    public function __construct(
        private PDO $pdo,
        private PasswordHasher $hasher,
    ) {
    }

    /**
     * @param string $setupToken Hex-encoded 32-byte out-of-band secret.
     * @param string $expectedDigest SHA-256 of the raw bytes, from private config.
     * @return int Identifier of the first, explicitly bootstrapped administrator.
     *
     * @throws RuntimeException If unauthorized, already initialized or unavailable.
     */
    public function create(
        string $email,
        string $password,
        string $setupToken,
        string $expectedDigest,
    ): int {
        if (!BootstrapSecret::valid($setupToken, $expectedDigest)) {
            throw new RuntimeException('Administrator setup denied.');
        }

        $registration = RegistrationData::fromStrings($email, $password);
        $passwordHash = $this->hasher->hash($registration->passwordForHashing());

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Administrator setup requires MySQL.');
        }

        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([self::LOCK_NAME]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Administrator setup unavailable.');
        }

        try {
            return $this->createUnderLock($registration->email, $passwordHash);
        } finally {
            $unlock = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute([self::LOCK_NAME]);
        }
    }

    private function createUnderLock(string $email, string $passwordHash): int
    {
        $this->pdo->beginTransaction();
        try {
            // An existing user, even an unverified one, disables bootstrap.
            // Never take over, delete, or promote existing accounts.
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0
                || (int) $this->pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() !== 0
                || (int) $this->pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn() !== 0) {
                throw new RuntimeException('Administrator setup denied.');
            }

            $this->pdo->exec(<<<'SQL'
INSERT INTO roles (role_key) VALUES ('admin')
SQL);
            $roleId = (int) $this->pdo->lastInsertId();
            if ($roleId < 1) {
                throw new RuntimeException('Administrator setup unavailable.');
            }

            // This privileged exception exists exclusively for first-time
            // setup and MUST be guarded by the future out-of-band operator UI.
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO users (
    email, password_hash, status, email_verified_at,
    approved_at, created_at, updated_at
) VALUES (
    ?, ?, 'active', UTC_TIMESTAMP(6),
    UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
)
SQL);
            $insert->execute([$email, $passwordHash]);
            $userId = (int) $this->pdo->lastInsertId();
            if ($userId < 1) {
                throw new RuntimeException('Administrator setup unavailable.');
            }

            $membership = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)
SQL);
            $membership->execute([$userId, $roleId]);
            if ($membership->rowCount() !== 1) {
                throw new RuntimeException('Administrator setup unavailable.');
            }

            $this->pdo->commit();
            return $userId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
