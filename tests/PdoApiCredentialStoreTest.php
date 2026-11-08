<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\PdoApiCredentialStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdoApiCredentialStoreTest extends TestCase
{
    public function testIssuanceChecksActiveOwnedClientInSql(): void
    {
        $prefix = 'opg_0123456789abcdef';
        $digest = str_repeat('a', 64);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('execute')
            ->with([$prefix, $digest, 13, 9])->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')
            ->willReturnCallback(static function (string $sql) use ($stmt): PDOStatement {
                self::assertStringContainsString('INSERT INTO api_credentials', $sql);
                self::assertStringContainsString('u.id = ?', $sql);
                self::assertStringContainsString("c.status = 'active'", $sql);
                self::assertStringContainsString("u.status = 'active'", $sql);
                self::assertStringContainsString('u.email_verified_at IS NOT NULL', $sql);
                self::assertStringContainsString('u.approved_at IS NOT NULL', $sql);
                return $stmt;
            });
        self::assertTrue((new PdoApiCredentialStore($pdo))
            ->createForOwner(9, 13, $prefix, $digest));
    }

    public function testRevocationRequiresCurrentOwnerAndUnrevokedKey(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('execute')
            ->with([17, 13, 9])->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')
            ->willReturnCallback(static function (string $sql) use ($stmt): PDOStatement {
                self::assertStringContainsString('UPDATE api_credentials AS k', $sql);
                self::assertStringContainsString('k.revoked_at IS NULL', $sql);
                self::assertStringContainsString('c.id = ?', $sql);
                self::assertStringContainsString('u.id = ?', $sql);
                self::assertStringContainsString('u.approved_at IS NOT NULL', $sql);
                return $stmt;
            });
        self::assertTrue((new PdoApiCredentialStore($pdo))
            ->revokeForOwner(9, 13, 17));
    }

    public function testInvalidActorOrKeyShapeCannotQueryDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $store = new PdoApiCredentialStore($pdo);
        self::assertFalse($store->createForOwner(0, 13, 'opg_0123456789abcdef', str_repeat('a', 64)));
        self::assertFalse($store->createForOwner(9, 13, 'invalid', str_repeat('a', 64)));
        self::assertFalse($store->revokeForOwner(9, 0, 17));
        self::assertNull($store->findActiveByPrefix('bad_prefix'));
    }
}
