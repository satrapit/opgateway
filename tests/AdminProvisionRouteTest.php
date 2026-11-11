<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Clients\AdminClientProvisioningService;
use OPGateway\Clients\ClientManagementService;
use OPGateway\Http\AdminActions;
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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * HTTP authorization and form parsing for the default-disabled provisioning route.
 * Does not touch real MySQL and asserts that denied inputs cannot write.
 */
final class AdminProvisionRouteTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProvisionRequiresSessionRoleCsrfAndExactFormFields(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(4, str_repeat('a', 64));
        $csrf = $session->csrfToken();

        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $accounts->method('currentSessionStamp')->willReturn(str_repeat('a', 64));
        $admins = $this->createStub(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('prepare');
        $limit = $this->createMock(AdminActionLimiter::class);
        $limit->expects(self::never())->method('allow');
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::never())->method('record');

        $review = new AdminReviewService(
            $pdo,
            new AdminActions(new AccountApprovalService($pdo), new ClientManagementService($pdo)),
            $limit,
            $events,
            new AdminClientProvisioningService($pdo),
        );
        $auth = new BrowserAuthController(
            $session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                $this->createStub(LoginAttemptLimiter::class),
                $this->createStub(SecurityEventRecorder::class),
            ),
            $accounts,
            $admins,
        );
        $adapter = new PrivateBrowserAdapter($session, $auth, $accounts, $admins, null, $review);
        $body = static fn (string $token, string $id = '9'): string => http_build_query([
            '_csrf' => $token,
            'action' => 'create_client',
            'target_id' => $id,
            'value' => 'Test client',
        ]);
        $dispatch = static fn (?string $type, ?string $data): array => $adapter->dispatch(
            'POST', '/admin/provision', true, $type, '192.0.2.1',
            str_repeat('f', 32), $data,
        );
        self::assertSame(415, $dispatch('application/json', $body($csrf))['status']);
        self::assertSame(403, $dispatch(
            'application/x-www-form-urlencoded', $body(str_repeat('0', 64)),
        )['status']);
        self::assertSame(400, $dispatch(
            'application/x-www-form-urlencoded', $body($csrf, '0'),
        )['status']);
        self::assertSame(400, $dispatch(
            'application/x-www-form-urlencoded', $body($csrf) . '&value=duplicate',
        )['status']);
        self::assertSame(400, $dispatch(
            'application/x-www-form-urlencoded', $body($csrf) . '&extra=yes',
        )['status']);

        $disabled = new PrivateBrowserAdapter($session, $auth, $accounts, $admins);
        self::assertSame(404, $disabled->dispatch(
            'POST', '/admin/provision', true, 'application/x-www-form-urlencoded',
            '192.0.2.1', str_repeat('f', 32), $body($csrf),
        )['status']);

        $noRole = $this->createStub(AdministratorAuthorization::class);
        $noRole->method('isAdministrator')->willReturn(false);
        $denied = new PrivateBrowserAdapter($session, $auth, $accounts, $noRole, null, $review);
        self::assertSame(403, $denied->dispatch(
            'POST', '/admin/provision', true, 'application/x-www-form-urlencoded',
            '192.0.2.1', str_repeat('f', 32), $body($csrf),
        )['status']);

        $session->logout();
    }
}
