<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;
use OPGateway\Security\CredentialActionLimiter;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use Throwable;

/** Set or replace an optional local password with transaction-safe revocation. */
final readonly class AccountSecurityService
{
    public function __construct(
        private PDO $pdo,
        private PasswordHasher $hasher,
        private CredentialActionLimiter $limiter,
        private SecurityEventRecorder $events,
    ) {
    }

    /** @return 'ok'|'denied'|'limited' */
    public function setPassword(
        int $userId, string $newPassword, string $sourceIp, string $requestId,
    ): string {
        return $this->change($userId, null, $newPassword, $sourceIp, $requestId, false);
    }

    /** @return 'ok'|'denied'|'limited' */
    public function changePassword(
        int $userId, string $currentPassword, string $newPassword,
        string $sourceIp, string $requestId,
    ): string {
        return $this->change(
            $userId, $currentPassword, $newPassword, $sourceIp, $requestId, true,
        );
    }

    /** @return 'ok'|'denied'|'limited' */
    private function change(
        int $userId, ?string $currentPassword, string $newPassword,
        string $sourceIp, string $requestId, bool $replacing,
    ): string {
        if ($userId < 1) {
            return 'denied';
        }
        $length = strlen($newPassword);
        if ($length < 12 || $length > 1024 || str_contains($newPassword, "\0")) {
            throw new InvalidArgumentException('Invalid password.');
        }
        if (!$this->limiter->allow($userId, $sourceIp)) {
            $this->events->record('account_security_throttled', $requestId);
            return 'limited';
        }

        $this->pdo->beginTransaction();
        try {
            $read = $this->pdo->prepare(<<<'SQL'
SELECT password_hash FROM users
WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL
AND approved_at IS NOT NULL FOR UPDATE
SQL);
            $read->execute([$userId]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !array_key_exists('password_hash', $row)) {
                $this->events->record('password_change_denied', $requestId);
                $this->pdo->commit();
                return 'denied';
            }
            $stored = $row['password_hash'];
            if ($replacing) {
                $allowed = is_string($stored) && $stored !== ''
                    && is_string($currentPassword)
                    && strlen($currentPassword) <= 1024
                    && $this->hasher->verify($currentPassword, $stored);
            } else {
                $allowed = $stored === null;
            }
            if (!$allowed) {
                $this->events->record('password_change_denied', $requestId);
                $this->pdo->commit();
                return 'denied';
            }

            $hash = $this->hasher->hash($newPassword);
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE users SET password_hash = ?,
auth_session_version = auth_session_version + 1,
updated_at = UTC_TIMESTAMP(6) WHERE id = ?
SQL);
            $update->execute([$hash, $userId]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Password update failed.');
            }
            $this->events->record($replacing ? 'password_changed' : 'password_set', $requestId);
            $this->pdo->commit();
            return 'ok';
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
