<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/**
 * User persistence boundary. A successful insert is NOT an approval,
 * verification or authenticated session.
 */
interface UserRepository
{
    /** @return int The new user identifier. */
    public function insertPending(string $email, string $passwordHash): int;
}
