<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\RuntimeSecrets;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeSecretsTest extends TestCase
{
    public function testPrivateKeyIsCreatedOnceAndRateLimitKeyIsDomainSeparated(): void
    {
        $root = sys_get_temp_dir() . '/opg-keys-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/storage', 0700, true));
        try {
            $first = RuntimeSecrets::key($root);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
            self::assertSame($first, RuntimeSecrets::key($root));
            $pepper = RuntimeSecrets::limiterKey($root);
            self::assertSame(32, strlen($pepper));
            self::assertNotSame(hex2bin($first), $pepper);
            self::assertSame(0, fileperms($root . '/storage/app-secret.key') & 0077);
        } finally {
            @unlink($root . '/storage/app-secret.key');
            @rmdir($root . '/storage');
            @rmdir($root);
        }
    }

    public function testWorldReadableSecretFailsClosed(): void
    {
        $root = sys_get_temp_dir() . '/opg-keys-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/storage', 0700, true));
        file_put_contents($root . '/storage/app-secret.key', bin2hex(random_bytes(32)));
        chmod($root . '/storage/app-secret.key', 0644);
        try {
            $this->expectException(RuntimeException::class);
            RuntimeSecrets::key($root);
        } finally {
            @unlink($root . '/storage/app-secret.key');
            @rmdir($root . '/storage');
            @rmdir($root);
        }
    }
}
