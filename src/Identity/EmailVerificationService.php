<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/**
 * Internal verification workflow; no HTTP routes or mail transport are enabled.
 *
 * A raw token must be delivered by a future secure mail adapter and must never
 * be recorded in HTTP responses, application logs or database records.
 */
final readonly class EmailVerificationService
{
    public function __construct(private EmailVerificationRepository $repository)
    {
    }

    /**
     * Issues a 30-minute token for an unverified, pending user.
     * Return the secret only to trusted, non-logging delivery code.
     */
    public function issueForUser(int $userId): string
    {
        $token = EmailVerificationToken::generate();
        $this->repository->issue(
            $userId,
            EmailVerificationToken::hash($token),
            EmailVerificationToken::ttlSeconds(),
        );
        return $token;
    }

    /** Returns false for malformed/expired/already-used tokens. */
    public function verify(string $token): bool
    {
        if (!preg_match('/^[a-fA-F0-9]{64}$/D', $token)) {
            return false;
        }
        return $this->repository->consume(EmailVerificationToken::hash($token));
    }
}
