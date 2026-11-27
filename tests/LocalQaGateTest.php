<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Quality\LocalQaGate;
use PHPUnit\Framework\TestCase;

final class LocalQaGateTest extends TestCase
{
    public function testVerifiedLocalHttpsAndLoopbackOnly(): void
    {
        $server = [
            'HTTPS' => 'on',
            'SERVER_NAME' => 'opgateway.test',
            'HTTP_HOST' => 'opgateway.test',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        self::assertTrue(LocalQaGate::allowed($server));
        $server['REMOTE_ADDR'] = '::1';
        self::assertTrue(LocalQaGate::allowed($server));
        $server['HTTPS'] = 'off';
        self::assertFalse(LocalQaGate::allowed($server));
    }

    public function testRemoteAndProductionHostsAreDeniedEvenWithForwardedHeaders(): void
    {
        $server = [
            'HTTPS' => 'on',
            'SERVER_NAME' => 'opgateway.test',
            'HTTP_HOST' => 'opgateway.test',
            'REMOTE_ADDR' => '203.0.113.15',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'opgateway.test',
        ];
        self::assertFalse(LocalQaGate::allowed($server));

        $server['REMOTE_ADDR'] = '127.0.0.1';
        $server['SERVER_NAME'] = 'opgateway.arsamnet.com';
        self::assertFalse(LocalQaGate::allowed($server));

        $server['SERVER_NAME'] = 'opgateway.test';
        $server['HTTP_HOST'] = 'opgateway.arsamnet.com';
        self::assertFalse(LocalQaGate::allowed($server));

        unset($server['HTTP_HOST']);
        self::assertFalse(LocalQaGate::allowed($server));
    }
}
