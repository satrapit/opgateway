<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\FoundationApplication;
use PHPUnit\Framework\TestCase;

final class FoundationApplicationTest extends TestCase
{
    public function testHealthHasNoSensitiveDetails(): void
    {
        self::assertSame(['status' => 200, 'body' => ['status' => 'ok']], (new FoundationApplication([]))->handle('GET', '/health', '127.0.0.1'));
    }

    public function testProxyIsUnavailableByDefault(): void
    {
        self::assertSame(503, (new FoundationApplication(['GATEWAY_PROXY_ENABLED' => 'true']))->handle('POST', '/v1/domains', '127.0.0.1')['status']);
    }

    public function testUnknownRoutesAreNotExposed(): void
    {
        self::assertSame(404, (new FoundationApplication([]))->handle('GET', '/admin', '127.0.0.1')['status']);
    }
}
