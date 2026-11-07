<?php

declare(strict_types=1);

namespace OPGateway\Security;

/**
 * Reserves attempts BEFORE password verification.
 *
 * Storage errors must throw and block login; never bypass throttling.
 */
interface LoginAttemptLimiter
{
    public function allow(string $sourceIp, string $email): bool;
}
