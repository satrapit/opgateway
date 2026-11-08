<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Clients\AdminClientProvisioningService;
use OPGateway\Http\AdminActions;
use OPGateway\Http\AdminReviewService;
use OPGateway\Identity\AccountApprovalService;
use OPGateway\Clients\ClientManagementService;
use OPGateway\Security\AdminActionLimiter;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminClientProvisioningServiceTest extends TestCase
{
    public function testAdminCreatesDisabledClientForEligibleOwner(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('execute')
            ->with(['Customer Integration', 9, 4])->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')
            ->willReturnCallback(static function (string $sql) use ($stmt): PDOStatement {
                self::assertStringContainsString("SELECT owner.id, ?, 'disabled'", $sql);
                self::assertStringContainsString('owner.approved_at IS NOT NULL', $sql);
                self::assertStringContainsString("r.role_key = 'admin'", $sql);
                self::assertStringContainsString("admin.status = 'active'", $sql);
                self::assertStringContainsString('admin.approved_at IS NOT NULL', $sql);
                return $stmt;
            });

        self::assertTrue((new AdminClientProvisioningService($pdo))
            ->createClient(4, 9, ' Customer Integration '));
    }

    public function testExactIpRequestIsCreatedDisabledWithAdminRoleCheck(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::exactly(3))->method('bindValue');
        $stmt->expects(self::once())->method('execute')->with()->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')
            ->willReturnCallback(static function (string $sql) use ($stmt): PDOStatement {
                self::assertStringContainsString('INSERT INTO client_ip_whitelists', $sql);
                self::assertStringContainsString('SELECT c.id, ?, 0', $sql);
                self::assertStringContainsString("c.status IN ('active', 'disabled')", $sql);
                self::assertStringContainsString('owner.approved_at IS NOT NULL', $sql);
                self::assertStringContainsString("r.role_key = 'admin'", $sql);
                return $stmt;
            });
        self::assertTrue((new AdminClientProvisioningService($pdo))
            ->requestIp(4, 13, '2001:db8::2'));
    }

    public function testInvalidProvisioningInputsNeverReachDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $service = new AdminClientProvisioningService($pdo);

        self::assertFalse($service->createClient(0, 9, 'Good'));
        self::assertFalse($service->createClient(4, 0, 'Good'));
        self::assertFalse($service->createClient(4, 9, ''));
        self::assertFalse($service->createClient(4, 9, str_repeat('x', 129)));
        self::assertFalse($service->createClient(4, 9, "bad\nlabel"));
        self::assertFalse($service->requestIp(0, 13, '192.0.2.5'));
        self::assertFalse($service->requestIp(4, 0, '192.0.2.5'));
        self::assertFalse($service->requestIp(4, 13, '192.0.2.0/24'));
        self::assertFalse($service->requestIp(4, 13, '192.0.2.5 '));
    }

    public function testProvisioningIsAuditedAndCommittedOnSuccess(): void
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($stmt);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $limiter = $this->createStub(AdminActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('admin_create_client_allowed', str_repeat('a', 32));
        $actions = new AdminActions(new AccountApprovalService($pdo), new ClientManagementService($pdo));
        $review = new AdminReviewService($pdo, $actions, $limiter, $events,
            new AdminClientProvisioningService($pdo));

        self::assertSame(['status' => 200, 'code' => 'ok'],
            $review->provision(4, '192.0.2.1', str_repeat('a', 32), 'create_client', 9, 'Demo'));
    }

    public function testFailedProvisionAuditRollsBackClientInsert(): void
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($stmt);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $limiter = $this->createStub(AdminActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->method('record')->willThrowException(new RuntimeException('Simulated audit failure'));
        $actions = new AdminActions(new AccountApprovalService($pdo), new ClientManagementService($pdo));
        $review = new AdminReviewService($pdo, $actions, $limiter, $events,
            new AdminClientProvisioningService($pdo));

        $this->expectException(RuntimeException::class);
        $review->provision(4, '192.0.2.1', str_repeat('b', 32), 'request_ip', 13, '192.0.2.5');
    }

    public function testProvisionRejectsUnknownOperationAndInvalidValueWithoutSql(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $limiter = $this->createMock(AdminActionLimiter::class);
        $limiter->expects(self::never())->method('allow');
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::never())->method('record');
        $actions = new AdminActions(new AccountApprovalService($pdo), new ClientManagementService($pdo));
        $review = new AdminReviewService($pdo, $actions, $limiter, $events,
            new AdminClientProvisioningService($pdo));

        self::assertSame(['status' => 400, 'code' => 'invalid_request'],
            $review->provision(4, '192.0.2.1', str_repeat('a', 32), 'delete_all', 13, 'anything'));
        self::assertSame(['status' => 400, 'code' => 'invalid_request'],
            $review->provision(4, '192.0.2.1', str_repeat('a', 32), 'request_ip', 13, 'localhost'));
        self::assertSame(['status' => 400, 'code' => 'invalid_request'],
            $review->provision(4, '192.0.2.1', str_repeat('a', 32), 'create_client', 13, ''));
    }
    public function testRequestingDuplicateIpDoesNotModifyPreviousApproval(): void
    {
        $conflict = new \PDOException('duplicate', '23000');
        $conflict->errorInfo = ['23000', 1062, 'duplicate'];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('execute')->willThrowException($conflict);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        self::assertFalse((new AdminClientProvisioningService($pdo))
            ->requestIp(4, 13, '192.0.2.5'));
    }

    public function testDisablingClientRequiresLiveAdministrator(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('execute')->with([7, 4])->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturnCallback(
            static function (string $sql) use ($stmt): PDOStatement {
                self::assertStringContainsString("c.status = 'active'", $sql);
                self::assertStringContainsString("SET c.status = 'disabled'", $sql);
                self::assertStringContainsString("r.role_key = 'admin'", $sql);
                self::assertStringContainsString('admin.approved_at IS NOT NULL', $sql);
                return $stmt;
            },
        );
        self::assertTrue((new AdminClientProvisioningService($pdo))->disableClient(4, 7));
    }

    public function testRevokingApprovedIpChecksAdministratorInSql(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('execute')->with([10, 4])->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturnCallback(
            static function (string $sql) use ($stmt): PDOStatement {
                self::assertStringContainsString('DELETE ip', $sql);
                self::assertStringContainsString('FROM client_ip_whitelists AS ip', $sql);
                self::assertStringContainsString("r.role_key = 'admin'", $sql);
                return $stmt;
            },
        );
        self::assertTrue((new AdminClientProvisioningService($pdo))->revokeIp(4, 10));
    }

    public function testEmergencyDisableAndRevokeOperationsAreAudited(): void
    {
        foreach ([
            ['disable_client', 'admin_disable_client_allowed'],
            ['revoke_ip', 'admin_revoke_ip_allowed'],
        ] as [$action, $event]) {
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('rowCount')->willReturn(1);
            $pdo = $this->createMock(PDO::class);
            $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
            $pdo->expects(self::once())->method('prepare')->willReturn($stmt);
            $pdo->expects(self::once())->method('commit')->willReturn(true);
            $limiter = $this->createStub(AdminActionLimiter::class);
            $limiter->method('allow')->willReturn(true);
            $events = $this->createMock(SecurityEventRecorder::class);
            $events->expects(self::once())->method('record')
                ->with($event, str_repeat('b', 32));
            $service = new AdminReviewService(
                $pdo,
                new AdminActions(new AccountApprovalService($pdo), new ClientManagementService($pdo)),
                $limiter,
                $events,
                new AdminClientProvisioningService($pdo),
            );
            self::assertSame(['status' => 200, 'code' => 'ok'],
                $service->execute(4, '192.0.2.1', str_repeat('b', 32), $action, 13));
        }
    }

}
