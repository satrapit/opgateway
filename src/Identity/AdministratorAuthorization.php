<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/**
 * Explicit role and account-state verification boundary.
 *
 * Any implementation must read the current database state on every call;
 * a session identifier or cached role alone never grants admin rights.
 */
interface AdministratorAuthorization
{
    public function isAdministrator(int $userId): bool;
}
