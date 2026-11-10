<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\CanonicalOrigin;
use PHPUnit\Framework\TestCase;

final class CanonicalOriginTest extends TestCase
{
    public function testCanonicalHostComesFromTheTrustedWebServer(): void
    {
        self::assertSame('https://opgateway.test', CanonicalOrigin::fromServer([
            'HTTPS' => 'on', 'SERVER_NAME' => 'opgateway.test',
            'HTTP_HOST' => 'opgateway.test',
        ]));
        self::assertNull(CanonicalOrigin::fromServer([
            'HTTPS' => 'on', 'SERVER_NAME' => 'opgateway.test',
            'HTTP_HOST' => 'attacker.example',
        ]));
        self::assertNull(CanonicalOrigin::fromServer([
            'HTTPS' => 'off', 'SERVER_NAME' => 'opgateway.test',
            'HTTP_HOST' => 'opgateway.test', 'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
        self::assertNull(CanonicalOrigin::fromServer([
            'HTTPS' => 'on', 'HTTP_HOST' => 'opgateway.test',
        ]));
    }
}
