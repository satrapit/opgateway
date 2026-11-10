<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use OPGateway\Mail\VerificationMailer;
use OPGateway\Security\PdoSignupLimiter;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Signup, email verification and resend. No session auto-login; the user
 * logs in with a password only after email proof has been consumed.
 *
 * Generic successful responses are identical for duplicate/unknown email.
 * Mail transport never receives any password, and no plaintext token is
 * persisted. Delivery failures must be retried explicitly by the user.
 */
final readonly class SelfServiceRegistration
{
    public function __construct(
        private PDO $pdo,
        private RegistrationService $users,
        private EmailVerificationService $verification,
        private PdoSignupLimiter $limiter,
        private VerificationMailer $mailer,
    ) {
    }

    /** @return 'accepted'|'limited' */
    public function register(
        string $email, string $password, string $sourceIp, string $origin,
    ): string {
        $data = RegistrationData::fromStrings($email, $password);
        if (!$this->limiter->allow($sourceIp, $data->email)) {
            return 'limited';
        }

        $token = null;
        $this->pdo->beginTransaction();
        try {
            try {
                $userId = $this->users->register($data);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    // An existing email must not reveal account presence.
                    $this->pdo->rollBack();
                    return 'accepted';
                }
                throw $exception;
            }
            $token = $this->verification->issueForUser($userId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        // Mail failure never rolls back the persisted account. Re-send is
        // available under the same throttles to recover delivery outages.
        try {
            $this->mailer->sendVerification($data->email, $token, $origin);
        } catch (Throwable) {
            throw new RuntimeException('Verification mail delivery unavailable.');
        }
        return 'accepted';
    }

    /** Generic response even if email is absent, already used or disabled. */
    public function resend(string $email, string $sourceIp, string $origin): string
    {
        $normalized = strtolower(trim($email));
        if (strlen($normalized) > 254
            || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return 'accepted';
        }
        if (!$this->limiter->allow($sourceIp, $normalized)) {
            return 'limited';
        }
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT id FROM users
WHERE email = ? AND status = 'pending'
  AND email_verified_at IS NULL AND signup_source = 'self_service'
LIMIT 1
SQL);
        $stmt->execute([$normalized]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            return 'accepted';
        }
        $token = $this->verification->issueForUser((int) $userId);
        try {
            $this->mailer->sendVerification($normalized, $token, $origin);
        } catch (Throwable) {
            throw new RuntimeException('Verification mail delivery unavailable.');
        }
        return 'accepted';
    }

    public function verify(string $token): bool
    {
        return $this->verification->verify($token);
    }
}
