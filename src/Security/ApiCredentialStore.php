<?php

declare(strict_types=1);

namespace OPGateway\Security;

/**
 * Credential persistence. Caller identity is authenticated separately;
 * mutations re-check owner eligibility in SQL to prevent confused deputies.
 */
interface ApiCredentialStore
{
    public function createForOwner(int $actorUserId, int $clientId, string $prefix, string $digest): bool;

    /** @return array{client_id:int,digest:string}|null */
    public function findActiveByPrefix(string $prefix): ?array;

    public function revokeForOwner(int $actorUserId, int $clientId, int $credentialId): bool;
}
