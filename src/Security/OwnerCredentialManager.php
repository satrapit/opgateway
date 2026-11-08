<?php

declare(strict_types=1);

namespace OPGateway\Security;

/**
 * Authorized owner-only key mutations. Plaintext keys are returned once,
 * exclusively from a successful issue() call and are never persisted.
 */
interface OwnerCredentialManager
{
    /** @return array{status:int,key?:string} */
    public function issue(int $ownerId, int $clientId, string $sourceIp, string $requestId): array;

    /** @return array{status:int} */
    public function revoke(
        int $ownerId,
        int $clientId,
        int $credentialId,
        string $sourceIp,
        string $requestId,
    ): array;
}
