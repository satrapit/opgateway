<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\MysqliConnectionProbe;
use PHPUnit\Framework\TestCase;

final class MysqliConnectionProbeTest extends TestCase
{
    public function testProbeDoesNotAttemptConnectionWhenConfigIsEmpty(): void
    {
        if (!extension_loaded('mysqli')) {
            self::assertSame('driver_unavailable', MysqliConnectionProbe::inspect([]));
            return;
        }

        self::assertSame('invalid_config', MysqliConnectionProbe::inspect([]));
    }

    public function testClassifiesKnownMysqlConnectionErrors(): void
    {
        self::assertSame('authentication_rejected', MysqliConnectionProbe::reason(1045));
        self::assertSame('access_denied', MysqliConnectionProbe::reason(1044));
        self::assertSame('unknown_database', MysqliConnectionProbe::reason(1049));
        self::assertSame('connection_failure', MysqliConnectionProbe::reason(2002));
        self::assertSame('connection_limit', MysqliConnectionProbe::reason(1040));
        self::assertSame('authentication_plugin', MysqliConnectionProbe::reason(2054));
        self::assertSame('unclassified', MysqliConnectionProbe::reason(0));
        self::assertSame('unclassified', MysqliConnectionProbe::reason(99999));
    }
}
