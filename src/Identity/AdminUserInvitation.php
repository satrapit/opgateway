<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use OPGateway\Mail\InvitationMailer;
use OPGateway\Security\AdminActionLimiter;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PDOException;
use Throwable;

/**
 * Invite an unprivileged owner. No password is chosen or emailed by an admin.
 *
 * Email verification is mandatory; manual approval remains mandatory for
 * signup_source=admin_invite. Only then is password recovery available.
 * SMTP delivery failures leave an unverified invite that can be resent.
 */
final readonly class AdminUserInvitation
{
    public function __construct(
        private PDO $pdo,
        private EmailVerificationService $verification,
        private InvitationMailer $mailer,
        private AdminActionLimiter $limiter,
        private SecurityEventRecorder $events,
    ) {
    }

    /** @return 'accepted'|'limited' */
    public function invite(
        int $administratorId, string $email, string $sourceIp,
        string $requestId, string $origin,
    ): string {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[^\x21-\x7E]/', $email) === 1) {
            throw new \InvalidArgumentException('Invalid user email.');
        }
        if (!$this->limiter->allow($administratorId, $sourceIp)) {
            $this->events->record('admin_user_invite_throttled', $requestId);
            return 'limited';
        }

        $this->pdo->beginTransaction();
        try {
            // Recheck active administrator *inside the SQL boundary*.
            $authorized = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM users AS operator
JOIN user_roles AS ur ON ur.user_id = operator.id
JOIN roles AS r ON r.id = ur.role_id AND r.role_key = 'admin'
WHERE operator.id = ? AND operator.status = 'active'
AND operator.email_verified_at IS NOT NULL
AND operator.approved_at IS NOT NULL LIMIT 1
SQL);
            $authorized->execute([$administratorId]);
            if ($authorized->fetchColumn() === false) {
                $this->events->record('admin_user_invite_denied', $requestId);
                $this->pdo->commit();
                return 'accepted';
            }

            $find = $this->pdo->prepare(<<<'SQL'
SELECT id, status, email_verified_at, signup_source
FROM users WHERE email = ? FOR UPDATE
SQL);
            $find->execute([$email]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if ($existing['status'] !== 'pending'
                    || $existing['email_verified_at'] !== null
                    || $existing['signup_source'] !== 'admin_invite') {
                    $this->events->record('admin_user_invite_denied', $requestId);
                    $this->pdo->commit();
                    return 'accepted';
                }
                $userId = (int) $existing['id'];
            } else {
                $create = $this->pdo->prepare(<<<'SQL'
INSERT INTO users (
    email, password_hash, status, signup_source, created_at, updated_at
) VALUES (?, NULL, 'pending', 'admin_invite', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
                try {
                    $create->execute([$email]);
                } catch (PDOException $exception) {
                    if ((string) $exception->getCode() === '23000') {
                        // A concurrent invite or existing user: never expose identity.
                        $this->pdo->rollBack();
                        return 'accepted';
                    }
                    throw $exception;
                }
                $userId = (int) $this->pdo->lastInsertId();
            }
            $token = $this->verification->issueForUser($userId);
            $this->events->record('admin_user_invited', $requestId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        // Delivery never transmits an initial or stored password.
        $this->mailer->sendInvitation($email, $token, $origin);
        return 'accepted';
    }
}
