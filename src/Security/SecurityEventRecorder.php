<?php

declare(strict_types=1);

namespace OPGateway\Security;

/**
 * Security audit metadata boundary. Raw credentials and identifiers are
 * prohibited; event codes and request IDs must be prevalidated.
 */
interface SecurityEventRecorder
{
    public function record(string $eventCode, string $requestId): void;
}
