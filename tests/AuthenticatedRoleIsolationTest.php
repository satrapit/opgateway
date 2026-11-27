<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AccountBrowserAdapter;
use OPGateway\Http\BrowserAuthController;
use OPGateway\Http\OwnerOverviewProvider;
use OPGateway\Http\PrivateBrowserAdapter;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\AuthenticationSessionVersion;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\OwnerCredentialManager;
use OPGateway\Security\SecurityEventRecorder;
use OPGateway\View\AdminPageRenderer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Authenticated role/identity transitions without real databases,
 * external mail, or browser credentials. These are authorization unit tests,
 * not a substitute for two-owner MySQL/HTTP acceptance tests.
 */
final class AuthenticatedRoleIsolationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLiveAdminRoleRemovalBlocksExistingAuthenticatedSession(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $stamp = AuthenticationSessionVersion::stamp(1);
        $session->authenticate(17, $stamp);

        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('currentSessionStamp')->willReturn($stamp);

        $hasRole = false;
        $roles = $this->createMock(AdministratorAuthorization::class);
        $roles->expects(self::exactly(3))->method('isAdministrator')
            ->with(17)
            ->willReturnCallback(static function (int $id) use (&$hasRole): bool {
                return $hasRole;
            });

        $views = $this->createMock(AdminPageRenderer::class);
        $views->expects(self::once())->method('dashboard')->willReturn('authorized admin');
        $views->expects(self::never())->method('section');

        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $this->createStub(LoginAttemptLimiter::class),
            $this->createStub(SecurityEventRecorder::class),
        );
        $auth = new BrowserAuthController($session, $login, $accounts, $roles);
        $adapter = new PrivateBrowserAdapter(
            $session, $auth, $accounts, $roles, null, null, $views,
        );
        $request = static fn (): array => $adapter->dispatch(
            'GET', '/admin', true, null, '192.0.2.5', str_repeat('a', 32), null,
        );

        self::assertSame(403, $request()['status'],
            'An authenticated owner without the admin role must be rejected.');
        $hasRole = true;
        $allowed = $request();
        self::assertSame(200, $allowed['status']);
        self::assertSame('authorized admin', $allowed['body']);

        $hasRole = false;
        self::assertSame(403, $request()['status'],
            'Removing the role must immediately deny the same active session.');
        self::assertSame(17, $session->currentUserId($accounts),
            'Role revocation must not accidentally authenticate another identity.');
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRevokedOwnerSessionCannotReachApiKeyIssuance(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $oldStamp = AuthenticationSessionVersion::stamp(1);
        $session->authenticate(17, $oldStamp);
        $csrf = $session->csrfToken();

        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('currentSessionStamp')->willReturn(
            AuthenticationSessionVersion::stamp(2),
        );
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $this->createStub(LoginAttemptLimiter::class),
            $this->createStub(SecurityEventRecorder::class),
        );
        $overview = $this->createMock(OwnerOverviewProvider::class);
        $overview->expects(self::never())->method('eligibleEmail');
        $overview->expects(self::never())->method('forUser');

        $manager = $this->createMock(OwnerCredentialManager::class);
        $manager->expects(self::never())->method('issue');
        $manager->expects(self::never())->method('revoke');
        $adapter = new AccountBrowserAdapter(
            $session, $login, $accounts, $overview, $manager,
        );

        $request = http_build_query([
            '_csrf' => $csrf,
            'client_id' => '13',
            'password' => 'long-test-password',
        ]);
        $denied = $adapter->dispatch(
            'POST', '/account/keys/issue', true,
            'application/x-www-form-urlencoded', '192.0.2.5',
            str_repeat('b', 32), $request,
        );
        self::assertSame(401, $denied['status']);
        self::assertFalse($session->csrfValid($csrf),
            'A stale authenticated session must lose its old CSRF token.');
        self::assertArrayNotHasKey('opgateway_user_id', $_SESSION);
        self::assertTrue(session_destroy());
    }
}
