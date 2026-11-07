<?php

declare(strict_types=1);

namespace OPGateway\Security;

/**
 * API client network authorization boundary.
 *
 * Callers must supply the verified transport source IP, not arbitrary HTTP
 * headers such as X-Forwarded-For.
 */
interface ClientIpAccessPolicy
{
    public function allows(int $clientId, string $sourceIp): bool;
}
