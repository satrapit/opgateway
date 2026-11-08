<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Security\PdoCredentialActionLimiter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdoCredentialActionLimiterTest extends TestCase
{
    public function testOwnerAndIpBucketsAreReservedBeforeAllowing(): void
    {
        $insert = $this->createStub(PDOStatement::class);
        $read = $this->createMock(PDOStatement::class);
        $counts = [1, 1, 11, 2];
        $read->expects(self::exactly(4))->method('fetchColumn')
            ->willReturnCallback(static function () use (&$counts): int {
                return array_shift($counts);
            });
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(8))->method('prepare')
            ->willReturnCallback(static function (string $sql) use ($insert, $read): PDOStatement {
                return str_starts_with(trim($sql), 'INSERT INTO') ? $insert : $read;
            });
        $limiter = new PdoCredentialActionLimiter($pdo, str_repeat('a', 32));
        self::assertTrue($limiter->allow(17, '192.0.2.3'));
        self::assertFalse($limiter->allow(17, '192.0.2.3'));
        self::assertSame([], $counts);
    }

    public function testInvalidSourceOrOwnerCannotReserveAnyBucket(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $limiter = new PdoCredentialActionLimiter($pdo, str_repeat('b', 32));
        self::assertFalse($limiter->allow(0, '192.0.2.3'));
        self::assertFalse($limiter->allow(17, 'not-an-ip'));
        self::assertFalse($limiter->allow(17, '192.0.2.3/32'));
    }

    public function testShortSecretCannotInitializeLimiter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PdoCredentialActionLimiter($this->createStub(PDO::class), 'short');
    }
}
