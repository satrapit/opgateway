<?php

declare(strict_types=1);

namespace OPGateway\Logging;

/** Allowlisted metadata only: never serialize headers, queries or request bodies. */
final class SafeRequestMetadata
{
    /** @return array{request_id:string,method:string,status:int,elapsed_ms:int} */
    public static function create(string $id, string $method, int $status, int $elapsedMs): array
    {
        return ['request_id' => $id, 'method' => $method, 'status' => $status, 'elapsed_ms' => $elapsedMs];
    }
}
