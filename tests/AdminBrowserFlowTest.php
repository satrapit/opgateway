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
use PHPUnit\Framework\TestCase;

/**
 * Browser flow tests without production MySQL or network calls.
 * The real endpoint separately enforces the HTTPS/operator-IP gate.
 */
final class AdminBrowserFlowTest extends TestCase
{
    public function testLoginDashboardAndLogoutWithoutOriginDependency(): void
    {
        $session = new class extends BrowserSession {
            public ?int $userId = null;

            public function csrfToken(): string
            {
                return str_repeat('a', 64);
            }

            public function csrfValid(string $submitted): bool
            {
                return hash_equals($this->csrfToken(), $submitted);
            }

            public function authenticate(int $userId): void
            {
                $this->userId = $userId;
            }

            public function currentUserId(LoginAccountRepository $accounts): ?int
            {
                return $this->userId;
            }

            public function logout(): void
            {
                $this->userId = null;
            }
        };

        $accounts = $this->createMock(LoginAccountRepository::class);
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
        self::assertNull($session->userId);

        $body = http_build_query([
            '_csrf' => str_repeat('a', 64), 'email' => 'admin@example.com',
            'password' => 'correct-horse-123',
        ]);
        $login = $adapter->dispatch('POST', '/admin/login', true,
            'application/x-www-form-urlencoded', '192.0.2.3', str_repeat('d', 32), $body);
        self::assertSame(303, $login['status']);
        self::assertSame('/admin', $login['location']);
        self::assertSame(17, $session->userId);

        $dashboard = $adapter->dispatch('GET', '/admin', true, null,
            '192.0.2.3', str_repeat('e', 32), null);
        self::assertSame(200, $dashboard['status']);
        self::assertStringContainsString('Sign out', $dashboard['body']);

        $logout = $adapter->dispatch('POST', '/admin/logout', true,
            'application/x-www-form-urlencoded', '192.0.2.3', str_repeat('f', 32),
            http_build_query(['_csrf' => str_repeat('a', 64)]));
        self::assertSame(303, $logout['status']);
        self::assertSame('/admin/login', $logout['location']);
        self::assertNull($session->userId);

        $after = $adapter->dispatch('GET', '/admin', true, null,
            '192.0.2.3', str_repeat('0', 32), null);
        self::assertSame(303, $after['status']);
        self::assertSame('/admin/login', $after['location']);
    }
}
