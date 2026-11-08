<?php

declare(strict_types=1);

namespace OPGateway\Security;

/**
 * Reserve administrator mutation attempts before invoking a domain service.
 * Implementations fail closed when storage is unavailable.
 */
interface AdminActionLimiter
{
    public function allow(int $administratorId, string $sourceIp): bool;
}
