<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\SealedSecret;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SealedSecretTest extends TestCase
{
    public function testEncryptedCredentialsAreRandomizedBoundAndTamperEvident(): void
    {
        $vault = new SealedSecret(str_repeat('ab', 32));
        $one = $vault->encrypt('very-long-private-password', 'provider:42');
        $two = $vault->encrypt('very-long-private-password', 'provider:42');
        self::assertNotSame($one, $two);
        self::assertStringStartsWith('v1:', $one);
        self::assertStringNotContainsString('very-long-private-password', $one);
        self::assertSame('very-long-private-password', $vault->decrypt($one, 'provider:42'));
        try {
            $vault->decrypt($one, 'provider:43');
            self::fail('Wrong owner must never decrypt.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
        $binary = base64_decode(substr($one, 3), true);
        $binary[31] = chr(ord($binary[31]) ^ 1);
        $this->expectException(RuntimeException::class);
        $vault->decrypt('v1:' . base64_encode($binary), 'provider:42');
    }

    public function testInvalidKeyCannotInitializeEncryption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SealedSecret('placeholder');
    }

    public function testSmtpContextIsIndependent(): void
    {
        $vault = new SealedSecret(str_repeat('10', 32));
        $c = $vault->encrypt('smtp-password', 'smtp:1');
        self::assertSame('smtp-password', $vault->decrypt($c, 'smtp:1'));
        $this->expectException(RuntimeException::class);
        $vault->decrypt($c, 'provider:1');
    }
}
