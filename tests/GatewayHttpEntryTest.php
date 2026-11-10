<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\GatewayHttpEntry;
use PHPUnit\Framework\TestCase;

final class GatewayHttpEntryTest extends TestCase
{
    public function testGatewayRequiresBearerAndNeverEnablesUnreviewedWrites(): void
    {
        $entry = new GatewayHttpEntry([]);
        $server = [
            'HTTPS' => 'on', 'SERVER_NAME' => 'gateway.example.net',
            'HTTP_HOST' => 'gateway.example.net',
        ];
        self::assertSame(404, $entry->handle('POST', '/v1/domains', $server)['status']);
        self::assertSame(404, $entry->handle('POST', '/v1/auth/login', $server)['status']);
        self::assertSame(401, $entry->handle('GET', '/v1/domains', $server)['status']);
    }

    public function testUntrustedHttpsAndHostNeverReachDatabase(): void
    {
        $entry = new GatewayHttpEntry([]);
        self::assertSame(403, $entry->handle('GET', '/v1/domains', [
            'HTTPS' => 'off', 'SERVER_NAME' => 'gateway.example.net',
            'HTTP_HOST' => 'gateway.example.net', 'HTTP_X_FORWARDED_PROTO' => 'https',
        ])['status']);
        self::assertSame(404, $entry->handle('GET', '/v1/domains', [
            'HTTPS' => 'on', 'SERVER_NAME' => 'gateway.example.net',
            'HTTP_HOST' => 'evil.example.net',
        ])['status']);
    }
}
