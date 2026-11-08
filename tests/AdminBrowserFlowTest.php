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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Browser flow tests without production MySQL or network calls.
 * The real endpoint separately enforces the HTTPS/operator-IP gate.
 */
final class AdminBrowserFlowTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoginDashboardAndLogoutWithoutOriginDependency(): void
    {
        // BrowserSession is final: use a real isolated PHP session, not a
        // subclass. This also verifies actual CSRF rotation and logout.
        $session = new BrowserSession();
        $session->start(true);
        $initialCsrf = $session->csrfToken();

        $accounts = $this->createMock(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $accounts->method('findEligibleByEmail')->willReturn([
            'id' => 17,
            'password_hash' => password_hash('correct-horse-123', PASSWORD_DEFAULT),
        ]);
        $admins = $this->createMock(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(true);
        $limits = $this->createMock(LoginAttemptLimiter::class);
        $limits->method('allow')->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);

        $controller = new BrowserAuthController(
            $session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                $limits,
                $events,
            ),
            $accounts,
            $admins,
        );
        $adapter = new PrivateBrowserAdapter($session, $controller, $accounts, $admins);

        $get = $adapter->dispatch('GET', '/admin/login', true, null, '192.0.2.3', str_repeat('b', 32), null);
        self::assertSame(200, $get['status']);
        self::assertStringContainsString('name="_csrf"', $get['body']);

        $wrongCsrfBody = http_build_query([
            '_csrf' => str_repeat('0', 64), 'email' => 'admin@example.com',
            'password' => 'correct-horse-123',
        ]);
        $denied = $adapter->dispatch('POST', '/admin/login', true,
            'application/x-www-form-urlencoded', '192.0.2.3', str_repeat('c', 32), $wrongCsrfBody);
        self::assertSame(403, $denied['status']);
        self::assertArrayNotHasKey('opgateway_user_id', $_SESSION);

        $body = http_build_query([
            '_csrf' => $initialCsrf, 'email' => 'admin@example.com',
            'password' => 'correct-horse-123',
        ]);
        $login = $adapter->dispatch('POST', '/admin/login', true,
            'application/x-www-form-urlencoded', '192.0.2.3', str_repeat('d', 32), $body);
        self::assertSame(303, $login['status']);
        self::assertSame('/admin', $login['location']);
        self::assertSame(17, $session->currentUserId($accounts));

        $dashboard = $adapter->dispatch('GET', '/admin', true, null,
            '192.0.2.3', str_repeat('e', 32), null);
        self::assertSame(200, $dashboard['status']);
        self::assertStringContainsString('Sign out', $dashboard['body']);

        $logoutCsrf = $session->csrfToken();
        self::assertNotSame($initialCsrf, $logoutCsrf);
        $logout = $adapter->dispatch('POST', '/admin/logout', true,
            'application/x-www-form-urlencoded', '192.0.2.3', str_repeat('f', 32),
            http_build_query(['_csrf' => $logoutCsrf]));
        self::assertSame(303, $logout['status']);
        self::assertSame('/admin/login', $logout['location']);
        self::assertSame(PHP_SESSION_NONE, session_status());
    }
}
