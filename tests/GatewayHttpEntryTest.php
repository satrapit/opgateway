<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\GatewayHttpEntry;
use PHPUnit\Framework\TestCase;

final class GatewayHttpEntryTest extends TestCase
{
    public function testDefaultOffAndWriteGateBeforeDatabase(): void
    {
        $entry = new GatewayHttpEntry([]);
        self::assertSame(503, $entry->handle('GET', '/v1/domains', [])['status']);
        $entry = new GatewayHttpEntry([
            'GATEWAY_PROXY_ENABLED' => 'true',
            'ACCOUNT_KEYS_ENABLED' => 'true',
            'APP_URL' => 'https://gateway.example.net',
        ]);
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'gateway.example.net'];
        self::assertSame(404, $entry->handle('POST', '/v1/domains', $server)['status']);
        self::assertSame(404, $entry->handle('POST', '/v1/auth/login', $server)['status']);
        self::assertSame(401, $entry->handle('GET', '/v1/domains', $server)['status']);
    }

    public function testUntrustedTransportAndWrongHostNeverReachDatabase(): void
    {
        $entry = new GatewayHttpEntry([
            'GATEWAY_PROXY_ENABLED' => 'true',
            'ACCOUNT_KEYS_ENABLED' => 'true',
            'APP_URL' => 'https://gateway.example.net',
        ]);
        self::assertSame(403, $entry->handle('GET', '/v1/domains', [
            'HTTPS' => 'off', 'HTTP_HOST' => 'gateway.example.net',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])['status']);
        self::assertSame(404, $entry->handle('GET', '/v1/domains', [
            'HTTPS' => 'on', 'HTTP_HOST' => 'evil.example.net',
        ])['status']);
    }
}
