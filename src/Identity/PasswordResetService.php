<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;
use OPGateway\Security\PdoPasswordResetLimiter;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Hashed 15-minute single-use reset tokens; generic email request responses.
 * Does not authenticate a browser session on password reset.
 */
final readonly class PasswordResetService
{
    public function __construct(
        private PDO $pdo,
        private PasswordHasher $hasher,
        private PdoPasswordResetLimiter $limiter,
        private PasswordResetMailer $mailer,
    ) {
    }

    /** @return 'accepted'|'limited' */
    public function request(string $email, string $sourceIp, string $origin): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[^\x21-\x7E]/', $email) === 1) {
            return 'accepted';
        }
        if (!$this->limiter->allow($sourceIp, $email)) {
            return 'limited';
        }
        $lookup = $this->pdo->prepare(<<<'SQL'
SELECT id FROM users WHERE email = ? AND status = 'active'
AND email_verified_at IS NOT NULL AND approved_at IS NOT NULL LIMIT 1
SQL);
        $lookup->execute([$email]);
        $userId = $lookup->fetchColumn();
        if ($userId === false) {
            return 'accepted';
        }

        $token = bin2hex(random_bytes(32));
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(<<<'SQL'
SELECT id FROM users WHERE id = ? AND status = 'active'
AND email_verified_at IS NOT NULL AND approved_at IS NOT NULL FOR UPDATE
SQL);
            $lock->execute([$userId]);
            if ($lock->fetchColumn() === false) {
                $this->pdo->rollBack();
                return 'accepted';
            }
            $old = $this->pdo->prepare(<<<'SQL'
UPDATE password_reset_tokens SET consumed_at = UTC_TIMESTAMP(6)
WHERE user_id = ? AND consumed_at IS NULL
SQL);
            $old->execute([$userId]);
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 900 SECOND), UTC_TIMESTAMP(6))
SQL);
            $insert->execute([$userId, hash('sha256', $token)]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        try {
            $this->mailer->sendPasswordReset($email, $token, $origin);
        } catch (Throwable $error) {
            throw new RuntimeException('Password reset delivery unavailable.', 0, $error);
        }
        return 'accepted';
    }

    public function reset(string $token, string $password): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/iD', $token) !== 1) {
            return false;
        }
        $length = strlen($password);
        if ($length < 12 || $length > 1024 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('Invalid password.');
        }
        $passwordHash = $this->hasher->hash($password);
        $this->pdo->beginTransaction();
        try {
            $read = $this->pdo->prepare(<<<'SQL'
SELECT t.user_id FROM password_reset_tokens t
JOIN users u ON u.id = t.user_id
WHERE t.token_hash = ? AND t.consumed_at IS NULL
AND t.expires_at > UTC_TIMESTAMP(6)
AND u.status = 'active' AND u.email_verified_at IS NOT NULL
AND u.approved_at IS NOT NULL FOR UPDATE
SQL);
            $read->execute([hash('sha256', strtolower($token))]);
            $userId = $read->fetchColumn();
            if ($userId === false) {
                $this->pdo->rollBack();
                return false;
            }
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP(6)
WHERE id = ? AND status = 'active'
AND email_verified_at IS NOT NULL AND approved_at IS NOT NULL
SQL);
            $update->execute([$passwordHash, $userId]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }
            $consume = $this->pdo->prepare(<<<'SQL'
UPDATE password_reset_tokens SET consumed_at = UTC_TIMESTAMP(6)
WHERE user_id = ? AND consumed_at IS NULL
SQL);
            $consume->execute([$userId]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
