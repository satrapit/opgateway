<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\BootstrapSecret;
use PHPUnit\Framework\TestCase;

final class BootstrapSecretTest extends TestCase
{
    public function testValidHexSecretIsAccepted(): void
    {
        $raw = random_bytes(32);
        $token = bin2hex($raw);
        $digest = hash('sha256', $raw);

        self::assertTrue(BootstrapSecret::valid($token, $digest));
        self::assertFalse(BootstrapSecret::valid(bin2hex(random_bytes(32)), $digest));
    }

    public function testEmptyAndMalformedSecretsAreDenied(): void
    {
        $digest = str_repeat('a', 64);

        self::assertFalse(BootstrapSecret::valid('', $digest));
        self::assertFalse(BootstrapSecret::valid(str_repeat('0', 64), ''));
        self::assertFalse(BootstrapSecret::valid(str_repeat('x', 64), $digest));
        self::assertFalse(BootstrapSecret::valid(str_repeat('0', 63), $digest));
        self::assertFalse(BootstrapSecret::valid(str_repeat('0', 64), strtoupper($digest)));
    }
}
