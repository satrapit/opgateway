<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Clients\IpEntry;
use PHPUnit\Framework\TestCase;

final class IpEntryTest extends TestCase
{
    public function testIPv4AndIPv6RoundTrip(): void
    {
        self::assertSame('192.0.2.55', IpEntry::unpack(IpEntry::pack('192.0.2.55')));
        self::assertSame('2001:db8::55', IpEntry::unpack(IpEntry::pack('2001:db8::55')));
        self::assertSame(4, strlen(IpEntry::pack('192.0.2.55')));
        self::assertSame(16, strlen(IpEntry::pack('2001:db8::55')));
    }

    public function testRefusesCidrRanges(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpEntry::pack('192.0.2.0/24');
    }

    public function testRefusesIpv6ZoneIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpEntry::pack('fe80::1%en0');
    }

    public function testRefusesMalformedBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpEntry::unpack('broken');
    }

    public function testRefusesWhitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpEntry::pack(' 192.0.2.55');
    }
}
