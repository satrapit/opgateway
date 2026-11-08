<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Security\PdoAdminActionLimiter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdoAdminActionLimiterTest extends TestCase
{
    public function testReservesBothBucketsAndStopsExcessiveAttempts(): void
    {
        $insert = $this->createStub(PDOStatement::class);
        $read = $this->createMock(PDOStatement::class);
        $counts = [1, 1, 41, 2];
        $read->expects(self::exactly(4))->method('fetchColumn')
            ->willReturnCallback(static function () use (&$counts): int {
                return array_shift($counts);
            });

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(8))->method('prepare')
            ->willReturnCallback(
                static function (string $sql) use ($insert, $read): PDOStatement {
                    return str_starts_with(trim($sql), 'INSERT INTO')
                        ? $insert : $read;
                },
            );

        $limiter = new PdoAdminActionLimiter($pdo, str_repeat('k', 32));
        self::assertTrue($limiter->allow(11, '192.0.2.4'));
        self::assertFalse($limiter->allow(11, '192.0.2.4'));
        self::assertSame([], $counts);
    }

    public function testInvalidSourceOrActorDoesNotTouchDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $limiter = new PdoAdminActionLimiter($pdo, str_repeat('k', 32));

        self::assertFalse($limiter->allow(0, '192.0.2.4'));
        self::assertFalse($limiter->allow(11, 'not-an-ip'));
        self::assertFalse($limiter->allow(11, '192.0.2.4/32'));
    }

    public function testWeakLimiterKeyIsRejected(): void
    {
        $pdo = $this->createStub(PDO::class);
        $this->expectException(InvalidArgumentException::class);
        new PdoAdminActionLimiter($pdo, 'too-short');
    }
}
