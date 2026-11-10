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

/** Independent Twig sections preserve fresh authorization. */
final class AdminSectionRouteTest extends TestCase
{
    /**
     * @param AdministratorAuthorization $admins
     */
    private function adapter(
        BrowserSession $session,
        LoginAccountRepository $accounts,
        AdministratorAuthorization $admins,
        ?AdminPageRenderer $pages,
    ): PrivateBrowserAdapter {
        $auth = new BrowserAuthController(
            $session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                $this->createStub(LoginAttemptLimiter::class),
                $this->createStub(SecurityEventRecorder::class),
            ),
            $accounts, $admins,
        );
        return new PrivateBrowserAdapter($session, $auth, $accounts, $admins, null, null, $pages);
    }

    private static function dispatch(PrivateBrowserAdapter $adapter, string $path): array
    {
        return $adapter->dispatch(
            'GET', $path, true, null, '192.0.2.4', str_repeat('a', 32), null,
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoggedOutSessionsRedirectAndMissingRendererIsNotUsed(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $admins = $this->createStub(AdministratorAuthorization::class);
        $pages = $this->createMock(AdminPageRenderer::class);
        $pages->expects(self::never())->method('section');

        $enabled = $this->adapter($session, $accounts, $admins, $pages);
        foreach (['/admin/users', '/admin/owners', '/admin/clients',
            '/admin/ips/pending', '/admin/ips/approved'] as $path) {
            $response = self::dispatch($enabled, $path);
            self::assertSame(303, $response['status']);
            self::assertSame('/admin/login', $response['location']);
        }

        $missingRenderer = $this->adapter($session, $accounts, $admins, null);
        self::assertSame(404, self::dispatch($missingRenderer, '/admin/clients')['status']);
        self::assertSame(404, self::dispatch($enabled, '/admin/unknown')['status']);
        self::assertSame(403, $enabled->dispatch('GET', '/admin/clients', false,
            null, '192.0.2.4', str_repeat('c', 32), null)['status']);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachAdminRouteHasFreshRoleCheckAndBoundedViewContract(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(11);

        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $admins = $this->createStub(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(true);
        $pages = $this->createMock(AdminPageRenderer::class);

        $paths = [
            '/admin/users' => 'users',
            '/admin/owners' => 'owners',
            '/admin/clients' => 'clients',
            '/admin/ips/pending' => 'pending_ips',
            '/admin/ips/approved' => 'approved_ips',
        ];
        $pages->expects(self::exactly(count($paths)))->method('section')
            ->willReturnCallback(function (string $section, string $csrf,
                string $label, ?array $pending, bool $actionsEnabled) use ($paths): string {
                self::assertContains($section, array_values($paths));
                self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $csrf);
                self::assertSame('Administrator', $label);
                self::assertNull($pending);
                self::assertFalse($actionsEnabled);
                return '<main>Page ' . $section . '</main>';
            });

        $adapter = $this->adapter($session, $accounts, $admins, $pages);
        foreach ($paths as $path => $expectedSection) {
            $response = self::dispatch($adapter, $path);
            self::assertSame(200, $response['status']);
            self::assertStringContainsString('Page ' . $expectedSection, $response['body']);
        }

        $deniedRole = $this->createStub(AdministratorAuthorization::class);
        $deniedRole->method('isAdministrator')->willReturn(false);
        $blocked = $this->adapter($session, $accounts, $deniedRole, $pages);
        self::assertSame(403, self::dispatch($blocked, '/admin/users')['status']);
        self::assertTrue(session_destroy());
    }
}
