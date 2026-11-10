<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\BrowserAuthController;
use OPGateway\Http\PrivateBrowserAdapter;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\SecurityEventRecorder;
use OPGateway\View\AdminPageRenderer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** A new rendering engine must not bypass existing trusted session/role checks. */
final class AdminViewMigrationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNewViewRendererKeepsExistingSessionAndRoleBoundary(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(11);

        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $admins = $this->createStub(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(true);

        $pages = $this->createMock(AdminPageRenderer::class);
        $pages->expects(self::once())->method('dashboard')
            ->with(self::isString(), 'Administrator', null, false)
            ->willReturn('<main id="twig-admin">Rendered by Twig view</main>');
        $pages->expects(self::once())->method('login')
            ->with(self::isString(), false)
            ->willReturn('<main id="twig-login">Secure login</main>');

        $auth = new BrowserAuthController(
            $session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                $this->createStub(LoginAttemptLimiter::class),
                $this->createStub(SecurityEventRecorder::class),
            ),
            $accounts, $admins,
        );
        $adapter = new PrivateBrowserAdapter(
            $session, $auth, $accounts, $admins, null, null, $pages,
        );
        $loginPage = $adapter->dispatch('GET', '/admin/login', true, null,
            '192.0.2.4', str_repeat('a', 32), null);
        self::assertSame(200, $loginPage['status']);
        self::assertStringContainsString('twig-login', $loginPage['body']);

        $dashboard = $adapter->dispatch('GET', '/admin', true, null,
            '192.0.2.4', str_repeat('b', 32), null);
        self::assertSame(200, $dashboard['status']);
        self::assertStringContainsString('twig-admin', $dashboard['body']);

        self::assertSame(403, $adapter->dispatch('GET', '/admin', false, null,
            '192.0.2.4', str_repeat('c', 32), null)['status']);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNonAdminIdentityNeverReachesNewViewRenderer(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(11);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $admins = $this->createStub(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(false);
        $pages = $this->createMock(AdminPageRenderer::class);
        $pages->expects(self::never())->method('dashboard');
        $auth = new BrowserAuthController($session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                $this->createStub(LoginAttemptLimiter::class),
                $this->createStub(SecurityEventRecorder::class),
            ), $accounts, $admins);
        $adapter = new PrivateBrowserAdapter($session, $auth, $accounts, $admins,
            null, null, $pages);
        self::assertSame(403, $adapter->dispatch('GET', '/admin', true, null,
            '192.0.2.4', str_repeat('c', 32), null)['status']);
        self::assertTrue(session_destroy());
    }
}
