<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/**
 * Reads credentials and current authorization status from trusted storage.
 * Never disclose existence or state of an account to a login requester.
 */
interface LoginAccountRepository
{
    /** @return array{id:int,password_hash:string}|null */
    public function findEligibleByEmail(string $email): ?array;

    /** Recheck state at each protected request; sessions are not authority. */
    public function isEligible(int $userId): bool;
}
