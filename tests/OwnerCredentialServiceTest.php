<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\ApiCredentialStore;
use OPGateway\Security\CredentialActionLimiter;
use OPGateway\Security\OwnerCredentialService;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OwnerCredentialServiceTest extends TestCase
{
    public function testSuccessfulIssuanceReturnsTokenOnlyAfterCommitAndAudit(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $limiter = $this->createStub(CredentialActionLimiter::class);
        $limiter->method('allow')->willReturn(true);

        $store = $this->createMock(ApiCredentialStore::class);
        $stored = [];
        $store->expects(self::once())->method('createForOwner')
            ->willReturnCallback(static function (int $user, int $client, string $prefix, string $digest) use (&$stored): bool {
                self::assertSame(9, $user);
                self::assertSame(13, $client);
                self::assertMatchesRegularExpression('/^opg_[a-f0-9]{16}$/D', $prefix);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $digest);
                $stored = [$prefix, $digest];
                return true;
            });
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('credential_issued', str_repeat('a', 32));
        $result = (new OwnerCredentialService($pdo, $store, $limiter, $events))
            ->issue(9, 13, '192.0.2.4', str_repeat('a', 32));

        self::assertSame(200, $result['status']);
        self::assertMatchesRegularExpression('/^opg_[a-f0-9]{16}\.[a-f0-9]{64}$/D', $result['key']);
        self::assertSame($stored[0], explode('.', $result['key'])[0]);
        self::assertNotSame($stored[1], explode('.', $result['key'])[1]);
        self::assertSame(hash('sha256', explode('.', $result['key'])[1]), $stored[1]);
    }

    public function testDeniedIssueAuditsAndReturnsNoBearer(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $limiter = $this->createStub(CredentialActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $store = $this->createStub(ApiCredentialStore::class);
        $store->method('createForOwner')->willReturn(false);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('credential_issue_denied', str_repeat('b', 32));
        $result = (new OwnerCredentialService($pdo, $store, $limiter, $events))
            ->issue(9, 999, '192.0.2.4', str_repeat('b', 32));
        self::assertSame(['status' => 403], $result);
        self::assertArrayNotHasKey('key', $result);
    }

    public function testRevocationIsOwnerScopedAndAudited(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $limiter = $this->createStub(CredentialActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $store = $this->createMock(ApiCredentialStore::class);
        $store->expects(self::once())->method('revokeForOwner')
            ->with(9, 13, 17)->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('credential_revoked', str_repeat('c', 32));

        self::assertSame(['status' => 200],
            (new OwnerCredentialService($pdo, $store, $limiter, $events))
                ->revoke(9, 13, 17, '192.0.2.4', str_repeat('c', 32)));
    }

    public function testFailedAuditRollsBackCredentialIssue(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $limiter = $this->createStub(CredentialActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $store = $this->createStub(ApiCredentialStore::class);
        $store->method('createForOwner')->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('credential_issued', str_repeat('d', 32))
            ->willThrowException(new RuntimeException('simulated audit failure'));

        $this->expectException(RuntimeException::class);
        (new OwnerCredentialService($pdo, $store, $limiter, $events))
            ->issue(9, 13, '192.0.2.4', str_repeat('d', 32));
    }

    public function testRateLimitRejectsIssuanceAndRevocationBeforeAnyWrite(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $store = $this->createMock(ApiCredentialStore::class);
        $store->expects(self::never())->method('createForOwner');
        $store->expects(self::never())->method('revokeForOwner');
        $limiter = $this->createStub(CredentialActionLimiter::class);
        $limiter->method('allow')->willReturn(false);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::exactly(2))->method('record')
            ->with('credential_action_throttled', str_repeat('e', 32));
        $service = new OwnerCredentialService($pdo, $store, $limiter, $events);
        self::assertSame(['status' => 429],
            $service->issue(9, 13, '192.0.2.4', str_repeat('e', 32)));
        self::assertSame(['status' => 429],
            $service->revoke(9, 13, 17, '192.0.2.4', str_repeat('e', 32)));
    }
}
