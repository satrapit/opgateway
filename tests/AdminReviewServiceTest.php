<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Clients\ClientManagementService;
use OPGateway\Http\AdminActions;
use OPGateway\Http\AdminOverviewRepository;
use OPGateway\Http\AdminReviewService;
use OPGateway\Http\BrowserAuthController;
use OPGateway\Http\PrivateBrowserAdapter;
use OPGateway\Identity\AccountApprovalService;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Security\AdminActionLimiter;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Fail-closed admin review mutation tests; no real database is modified. */
final class AdminReviewServiceTest extends TestCase
{
    private function actions(PDO $pdo): AdminActions
    {
        return new AdminActions(
            new AccountApprovalService($pdo),
            new ClientManagementService($pdo),
        );
    }

    public function testSuccessfulApprovalWritesAuditBeforeCommit(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $pdo->expects(self::once())->method('commit')->willReturn(true);

        $limiter = $this->createMock(AdminActionLimiter::class);
        $limiter->expects(self::once())->method('allow')->with(11, '192.0.2.4')->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('admin_approve_user_allowed', str_repeat('a', 32));

        $service = new AdminReviewService($pdo, $this->actions($pdo), $limiter, $events);
        self::assertSame(['status' => 200, 'code' => 'ok'],
            $service->execute(11, '192.0.2.4', str_repeat('a', 32), 'approve_user', 22));
    }

    public function testDeniedDomainActionIsAuditedAndFailsClosed(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('rowCount')->willReturn(0);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $pdo->expects(self::once())->method('commit')->willReturn(true);

        $limiter = $this->createStub(AdminActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('admin_activate_client_denied', str_repeat('b', 32));

        $service = new AdminReviewService($pdo, $this->actions($pdo), $limiter, $events);
        self::assertSame(['status' => 403, 'code' => 'action_denied'],
            $service->execute(11, '192.0.2.4', str_repeat('b', 32), 'activate_client', 22));
    }

    public function testFailedAuditRollsBackMutation(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');

        $limiter = $this->createStub(AdminActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->willThrowException(new RuntimeException('simulated audit write failure'));

        $this->expectException(RuntimeException::class);
        (new AdminReviewService($pdo, $this->actions($pdo), $limiter, $events))
            ->execute(11, '192.0.2.4', str_repeat('c', 32), 'approve_ip', 5);
    }

    public function testRateLimitStopsDatabaseMutationAndWritesThrottleEvent(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('prepare');

        $limiter = $this->createStub(AdminActionLimiter::class);
        $limiter->method('allow')->willReturn(false);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('admin_review_throttled', str_repeat('d', 32));

        self::assertSame(['status' => 429, 'code' => 'too_many_requests'],
            (new AdminReviewService($pdo, $this->actions($pdo), $limiter, $events))
                ->execute(11, '192.0.2.4', str_repeat('d', 32), 'approve_user', 5));
    }

    public function testUnknownActionDoesNotTouchDatabaseOrLimiter(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $limiter = $this->createMock(AdminActionLimiter::class);
        $limiter->expects(self::never())->method('allow');
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::never())->method('record');

        self::assertSame(['status' => 400, 'code' => 'invalid_request'],
            (new AdminReviewService($pdo, $this->actions($pdo), $limiter, $events))
                ->execute(11, '192.0.2.4', str_repeat('e', 32), 'reset_database', 5));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReviewRouteRequiresAuthenticatedAdminAndValidCsrf(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(17);
        $csrf = $session->csrfToken();

        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $admins = $this->createStub(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('prepare');
        $limiter = $this->createStub(AdminActionLimiter::class);
        $events = $this->createStub(SecurityEventRecorder::class);
        $review = new AdminReviewService($pdo, $this->actions($pdo), $limiter, $events);

        $loginLimiter = $this->createStub(LoginAttemptLimiter::class);
        $loginEvents = $this->createStub(SecurityEventRecorder::class);
        $auth = new BrowserAuthController(
            $session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                $loginLimiter, $loginEvents,
            ),
            $accounts, $admins,
        );
        $adapter = new PrivateBrowserAdapter(
            $session, $auth, $accounts, $admins, null, $review,
        );
        $makeForm = static fn (string $csrf, string $id = '2'): string => http_build_query([
            '_csrf' => $csrf, 'action' => 'approve_user', 'target_id' => $id,
        ]);
        $dispatch = static fn (?string $contentType, ?string $body): array => $adapter->dispatch(
            'POST', '/admin/review', true, $contentType, '192.0.2.4',
            str_repeat('f', 32), $body,
        );

        self::assertSame(415, $dispatch('application/json', $makeForm($csrf))['status']);
        self::assertSame(403, $dispatch('application/x-www-form-urlencoded',
            $makeForm(str_repeat('0', 64)))['status']);
        self::assertSame(400, $dispatch('application/x-www-form-urlencoded',
            $makeForm($csrf, '02'))['status']);
        self::assertSame(400, $dispatch('application/x-www-form-urlencoded',
            $makeForm($csrf, '92233720368547758070'))['status']);
        self::assertSame(400, $dispatch('application/x-www-form-urlencoded',
            $makeForm($csrf) . '&unexpected=1')['status']);

        $disabled = new PrivateBrowserAdapter($session, $auth, $accounts, $admins);
        self::assertSame(404, $disabled->dispatch(
            'POST', '/admin/review', true, 'application/x-www-form-urlencoded',
            '192.0.2.4', str_repeat('f', 32), $makeForm($csrf),
        )['status']);
        // Changing the live role to non-administrator denies mutations
        // before any rate-limiter or domain SQL is reached.
        $nonAdmin = $this->createStub(AdministratorAuthorization::class);
        $nonAdmin->method('isAdministrator')->willReturn(false);
        $blocked = new PrivateBrowserAdapter(
            $session, $auth, $accounts, $nonAdmin, null, $review,
        );
        self::assertSame(403, $blocked->dispatch(
            'POST', '/admin/review', true, 'application/x-www-form-urlencoded',
            '192.0.2.4', str_repeat('f', 32), $makeForm($csrf),
        )['status']);

        $session->logout();
        self::assertSame(401, $adapter->dispatch(
            'POST', '/admin/review', true, 'application/x-www-form-urlencoded',
            '192.0.2.4', str_repeat('f', 32), $makeForm($csrf),
        )['status']);
    }
}
