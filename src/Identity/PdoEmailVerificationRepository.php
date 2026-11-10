<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use PDO;
use Throwable;

/**
 * InnoDB-backed email verification with transactional, one-time consumption.
 *
 * A row lock serializes competing claims. Both the user and token state
 * transitions commit together. Use a database user with limited privileges.
 */
final readonly class PdoEmailVerificationRepository implements EmailVerificationRepository
{
    public function __construct(private PDO $pdo, private bool $autoApproveSelfService = false)
    {
    }

    public function issue(int $userId, string $tokenHash, int $ttlSeconds): void
    {
        if ($userId < 1 || $ttlSeconds < 1 || $ttlSeconds > 86400
            || !preg_match('/^[a-f0-9]{64}$/D', $tokenHash)) {
            throw new \InvalidArgumentException('Invalid verification parameters.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO email_verification_tokens
    (user_id, token_hash, expires_at, created_at)
SELECT id, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), UTC_TIMESTAMP(6)
FROM users WHERE id = ? AND email_verified_at IS NULL AND status = 'pending'
SQL);
        $statement->execute([$tokenHash, $ttlSeconds, $userId]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('User is not eligible for email verification.');
        }
    }

    public function consume(string $tokenHash): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $tokenHash)) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(<<<'SQL'
SELECT user_id
FROM email_verification_tokens
WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)
FOR UPDATE
SQL);
            $select->execute([$tokenHash]);
            $userId = $select->fetchColumn();
            if ($userId === false) {
                $this->pdo->rollBack();
                return false;
            }

            // Only accounts explicitly created through public self-service
            // may be auto-approved after proving mailbox ownership.
            // Legacy/manual registrations retain administrator approval.
            $sql = $this->autoApproveSelfService
                ? <<<'SQL'
UPDATE users
SET email_verified_at = UTC_TIMESTAMP(6),
    approved_at = CASE WHEN signup_source = 'self_service'
        THEN UTC_TIMESTAMP(6) ELSE approved_at END,
    status = CASE WHEN signup_source = 'self_service'
        THEN 'active' ELSE status END,
    updated_at = UTC_TIMESTAMP(6)
WHERE id = ? AND email_verified_at IS NULL AND status = 'pending'
SQL
                : <<<'SQL'
UPDATE users
SET email_verified_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
WHERE id = ? AND email_verified_at IS NULL AND status = 'pending'
SQL;
            $updateUser = $this->pdo->prepare($sql);
            $updateUser->execute([$userId]);
            if ($updateUser->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $updateToken = $this->pdo->prepare(<<<'SQL'
UPDATE email_verification_tokens
SET consumed_at = UTC_TIMESTAMP(6)
WHERE token_hash = ? AND consumed_at IS NULL
SQL);
            $updateToken->execute([$tokenHash]);
            if ($updateToken->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $revokeOthers = $this->pdo->prepare(<<<'SQL'
UPDATE email_verification_tokens
SET consumed_at = UTC_TIMESTAMP(6)
WHERE user_id = ? AND consumed_at IS NULL
SQL);
            $revokeOthers->execute([$userId]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
