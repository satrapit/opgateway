<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Fail-closed HTTP foundation; no upstream calls are permitted in this phase.
 * The health endpoint discloses no deployment, database or upstream details.
 */
final readonly class FoundationApplication
{
    /** @param array<string, string> $config */
    public function __construct(private array $config)
    {
    }

    /** @return array{status:int,body:array<string,string>} */
    public function handle(string $method, string $path, string $remoteAddress): array
    {
        if ($method === 'GET' && $path === '/health') {
            return ['status' => 200, 'body' => ['status' => 'ok']];
        }
        if (str_starts_with($path, '/v1/') || $path === '/v1') {
            return ['status' => 503, 'body' => ['error' => 'gateway_not_configured']];
        }
        return ['status' => 404, 'body' => ['error' => 'not_found']];
    }
}
