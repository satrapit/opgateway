<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/**
 * Persistence contract for single-use email ownership proofs.
 *
 * All implementations must consume a token and mark its user verified in
 * the same transaction. Never store the raw token.
 */
interface EmailVerificationRepository
{
    public function issue(int $userId, string $tokenHash, int $ttlSeconds): void;

    /** Return false for unknown, expired, consumed, or ineligible tokens. */
    public function consume(string $tokenHash): bool;
}
