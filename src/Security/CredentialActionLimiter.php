<?php

declare(strict_types=1);

namespace OPGateway\Security;

/** Bound attempts for owner-initiated key issuance and revocation. */
interface CredentialActionLimiter
{
    public function allow(int $ownerId, string $sourceIp): bool;
}
