<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\IpAddress;
use PHPUnit\Framework\TestCase;

final class IpAddressTest extends TestCase
{
    public function testExactIpMatching(): void
    {
        self::assertTrue(IpAddress::allowed('192.0.2.7', ['192.0.2.7']));
        self::assertTrue(IpAddress::allowed('2001:db8::1', ['2001:db8::1']));
        self::assertFalse(IpAddress::allowed('192.0.2.8', ['192.0.2.7']));
        self::assertFalse(IpAddress::allowed('invalid', ['invalid']));
        self::assertFalse(IpAddress::allowed('192.0.2.7', []));
    }
}
