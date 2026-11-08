<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Read-only, owner-scoped dashboard data. Never return secret hashes or bearer
 * values. Every query must independently restrict rows to the current owner.
 */
interface OwnerOverviewProvider
{
    /**
     * @return array{
     *   email:string,
     *   clients:list<array{id:int,label:string,status:string}>,
     *   credentials:list<array{id:int,client_id:int,prefix:string,revoked:bool}>,
     *   ips:list<array{id:int,client_id:int,address:string,enabled:bool}>
     * }|null Null denotes an ineligible owner.
     */
    public function forUser(int $ownerId): ?array;

    /** Look up an eligible account only (used for password reconfirmation). */
    public function eligibleEmail(int $ownerId): ?string;
}
