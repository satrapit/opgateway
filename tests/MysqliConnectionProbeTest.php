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
}
