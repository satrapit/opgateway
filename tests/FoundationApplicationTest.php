<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\FoundationApplication;
use PHPUnit\Framework\TestCase;

final class FoundationApplicationTest extends TestCase
{
    public function testHealthHasNoSensitiveDetails(): void
    {
        self::assertSame(['status' => 200, 'body' => ['status' => 'ok']],
            (new FoundationApplication())->handle('GET', '/health'));
    }

    public function testGatewayRoutesAreNotShadowedByASecondFoundationRouter(): void
    {
        self::assertSame(404, (new FoundationApplication())->handle('GET', '/v1/domains')['status']);
    }

    public function testOldDatabaseDiagnosticRouteIsNotExposed(): void
    {
        self::assertSame(404, (new FoundationApplication())->handle('GET', '/db-connection-test.php')['status']);
    }

    public function testUnknownRoutesAreNotExposed(): void
    {
        self::assertSame(404, (new FoundationApplication())->handle('GET', '/other')['status']);
    }
}
