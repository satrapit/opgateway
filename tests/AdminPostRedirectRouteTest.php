<?php

declare(strict_types=1);

namespace OPGateway\Tests;

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
use OPGateway\View\AdminPageRenderer;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * A successful review POST always redirects to its one Twig section.
 * Mocked PDO never connects to or modifies a real database.
 */
final class AdminPostRedirectRouteTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSuccessfulReviewPostRedirectsToOwningPage(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(11, str_repeat('a', 64));
        $csrf = $session->csrfToken();

        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $accounts->method('currentSessionStamp')->willReturn(str_repeat('a', 64));
        $admins = $this->createStub(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(true);
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        $limiter = $this->createStub(AdminActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $events = $this->createStub(SecurityEventRecorder::class);
        $review = new AdminReviewService(
            $pdo,
            new AdminActions(new AccountApprovalService($pdo), new ClientManagementService($pdo)),
            $limiter,
            $events,
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

        $body = http_build_query([
            '_csrf' => $csrf,
            'action' => 'approve_user',
            'target_id' => '23',
        ]);
        $request = static fn (PrivateBrowserAdapter $adapter): array =>
            $adapter->dispatch(
                'POST', '/admin/review', true, 'application/x-www-form-urlencoded',
                '192.0.2.4', str_repeat('a', 32), $body,
            );

        $twig = new PrivateBrowserAdapter(
            $session, $auth, $accounts, $admins, null, $review,
            $this->createStub(AdminPageRenderer::class),
        );
        $response = $request($twig);
        self::assertSame(303, $response['status']);
        self::assertSame('/admin/users', $response['location']);

        self::assertTrue(session_destroy());
    }
}
