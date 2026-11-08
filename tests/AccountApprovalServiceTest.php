<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\AccountApprovalService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class AccountApprovalServiceTest extends TestCase
{
    public function testApprovalUsesMySqlCompatibleSelfJoinWithActorAuthorization(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with([11, 22])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('rowCount')
            ->willReturn(1);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturnCallback(static function (string $sql) use ($statement): PDOStatement {
                self::assertMatchesRegularExpression(
                    '/UPDATE users AS target\\s+JOIN users AS admin ON admin\\.id = \\?/',
                    $sql,
                );
                self::assertStringContainsString('JOIN user_roles AS ur', $sql);
                self::assertStringContainsString("r.role_key = 'admin'", $sql);
                self::assertStringContainsString("target.status = 'pending'", $sql);
                self::assertStringContainsString('target.id <> admin.id', $sql);
                self::assertStringContainsString("admin.status = 'active'", $sql);
                self::assertStringContainsString('admin.email_verified_at IS NOT NULL', $sql);
                self::assertStringContainsString('admin.approved_at IS NOT NULL', $sql);
                self::assertStringNotContainsString('SELECT 1 FROM users', $sql);
                return $statement;
            });

        self::assertTrue((new AccountApprovalService($pdo))->approve(11, 22));
    }

    public function testInvalidAndSelfApprovalNeverReachDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $service = new AccountApprovalService($pdo);

        self::assertFalse($service->approve(0, 22));
        self::assertFalse($service->approve(11, 0));
        self::assertFalse($service->approve(11, 11));
    }

    public function testNonMatchingOrIneligibleAccountIsNotApproved(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([11, 22])->willReturn(true);
        $statement->expects(self::once())->method('rowCount')->willReturn(0);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);

        self::assertFalse((new AccountApprovalService($pdo))->approve(11, 22));
    }
}
