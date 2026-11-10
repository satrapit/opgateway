<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Public landing redirect, health probe and unknown-route response.
 * Admin, owner and gateway requests have their own actual handlers.
 */
final class FoundationApplication
{
    /** @return array{status:int,body:array<string,string>,location?:string} */
    public function handle(string $method, string $path): array
    {
        // The gateway has no public marketing homepage. Land in the
        // account area, which performs its own session/eligibility checks.
        if ($method === 'GET' && $path === '/') {
            return ['status' => 303, 'body' => [], 'location' => '/account'];
        }
        if ($method === 'GET' && $path === '/health') {
            return ['status' => 200, 'body' => ['status' => 'ok']];
        }
        return ['status' => 404, 'body' => ['error' => 'not_found']];
    }
}
