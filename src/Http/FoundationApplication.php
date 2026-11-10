<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Only the public health probe and unknown-route response.
 * Admin, owner and gateway requests have their own actual handlers.
 */
final class FoundationApplication
{
    /** @return array{status:int,body:array<string,string>} */
    public function handle(string $method, string $path): array
    {
        if ($method === 'GET' && $path === '/health') {
            return ['status' => 200, 'body' => ['status' => 'ok']];
        }
        return ['status' => 404, 'body' => ['error' => 'not_found']];
    }
}
