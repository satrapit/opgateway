<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AccountBrowserAdapter;
use OPGateway\Http\OwnerOverviewProvider;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Security\ApiKey;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\OwnerCredentialManager;
use OPGateway\Security\SecurityEventRecorder;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AccountBrowserAdapterTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOwnerLoginOneTimeIssueRevocationAndCsrfRotation(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $csrf = $session->csrfToken();
        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $accounts->method('findEligibleByEmail')->willReturn([
            'id' => 17, 'password_hash' => password_hash('correct-owner-password', PASSWORD_DEFAULT),
        ]);
        $limits = $this->createStub(LoginAttemptLimiter::class);
        $limits->method('allow')->willReturn(true);
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $limits, $this->createStub(SecurityEventRecorder::class),
        );
        $overview = $this->createStub(OwnerOverviewProvider::class);
        $overview->method('eligibleEmail')->willReturn('owner@example.com');
        $overview->method('forUser')->willReturn([
            'email' => 'owner@example.com',
            'clients' => [['id' => 13, 'label' => 'Integration', 'status' => 'active']],
            'credentials' => [],
            'ips' => [['id' => 5, 'client_id' => 13, 'address' => '192.0.2.7', 'enabled' => true]],
        ]);

        $issuedKey = ApiKey::issue()['key'];
        $manager = $this->createMock(OwnerCredentialManager::class);
        $manager->expects(self::once())->method('issue')
            ->with(17, 13, '192.0.2.7', str_repeat('b', 32))
            ->willReturn(['status' => 200, 'key' => $issuedKey]);
        $manager->expects(self::once())->method('revoke')
            ->with(17, 13, 19, '192.0.2.7', str_repeat('b', 32))
            ->willReturn(['status' => 200]);
        $adapter = new AccountBrowserAdapter($session, $login, $accounts, $overview, $manager);
        $send = static fn (
            string $method, string $path, ?string $contentType = null, ?string $body = null,
        ): array => $adapter->dispatch(
            $method, $path, true, $contentType, '192.0.2.7', str_repeat('b', 32), $body,
        );
        $formType = 'application/x-www-form-urlencoded';
        $post = static fn (array $fields): string => http_build_query($fields);

        self::assertSame(303, $send('GET', '/account')['status']);
        self::assertSame(200, $send('GET', '/account/login')['status']);
        self::assertSame(403, $send('POST', '/account/login', $formType, $post([
            '_csrf' => str_repeat('0', 64), 'email' => 'owner@example.com',
            'password' => 'correct-owner-password',
        ]))['status']);
        $loginResult = $send('POST', '/account/login', $formType, $post([
            '_csrf' => $csrf, 'email' => 'owner@example.com',
            'password' => 'correct-owner-password',
        ]));
        self::assertSame(303, $loginResult['status']);
        self::assertSame('/account', $loginResult['location']);
        self::assertSame(17, $session->currentUserId($accounts));

        $dashboard = $send('GET', '/account');
        self::assertSame(200, $dashboard['status']);
        self::assertStringContainsString('API clients', $dashboard['body']);
        $clientPage = $send('GET', '/account/clients');
        self::assertSame(200, $clientPage['status']);
        self::assertStringContainsString('Integration', $clientPage['body']);
        self::assertStringContainsString('action="/account/keys/issue"', $clientPage['body']);
        $loggedCsrf = $session->csrfToken();

        $wrongPassword = $post([
            '_csrf' => $loggedCsrf, 'client_id' => '13', 'password' => 'invalid',
        ]);
        self::assertSame(403, $send('POST', '/account/keys/issue',
            $formType, $wrongPassword)['status']);
        $correctIssue = $post([
            '_csrf' => $loggedCsrf, 'client_id' => '13', 'password' => 'correct-owner-password',
        ]);
        $issued = $send('POST', '/account/keys/issue', $formType, $correctIssue);
        self::assertSame(200, $issued['status']);
        self::assertStringContainsString($issuedKey, $issued['body']);
        self::assertArrayNotHasKey('location', $issued);
        self::assertStringNotContainsString($issuedKey, json_encode($_SESSION, JSON_THROW_ON_ERROR));
        self::assertNotSame($loggedCsrf, $session->csrfToken());
        self::assertSame(403, $send('POST', '/account/keys/issue',
            $formType, $correctIssue)['status']);

        $revokeCsrf = $session->csrfToken();
        $revokeBody = $post([
            '_csrf' => $revokeCsrf, 'client_id' => '13', 'credential_id' => '19',
        ]);
        self::assertSame(400, $send('POST', '/account/keys/revoke', $formType,
            $revokeBody . '&extra=1')['status']);
        self::assertSame(303, $send('POST', '/account/keys/revoke',
            $formType, $revokeBody)['status']);
        self::assertNotSame($revokeCsrf, $session->csrfToken());

        $logoutCsrf = $session->csrfToken();
        self::assertSame(303, $send('POST', '/account/logout', $formType,
            $post(['_csrf' => $logoutCsrf]))['status']);
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCredentialRoutesAreDisabledUnlessExplicitlyConfigured(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(17);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $this->createStub(LoginAttemptLimiter::class),
            $this->createStub(SecurityEventRecorder::class),
        );
        $overview = $this->createStub(OwnerOverviewProvider::class);
        $overview->method('forUser')->willReturn([
            'email' => 'owner@example.com',
            'clients' => [['id' => 13, 'label' => 'Integration', 'status' => 'active']],
            'credentials' => [], 'ips' => [],
        ]);
        $adapter = new AccountBrowserAdapter($session, $login, $accounts, $overview);
        $csrf = $session->csrfToken();
        $form = http_build_query([
            '_csrf' => $csrf, 'client_id' => '13', 'password' => 'some-long-password',
        ]);
        self::assertSame(404, $adapter->dispatch('POST', '/account/keys/issue',
            true, 'application/x-www-form-urlencoded', '192.0.2.7',
            str_repeat('c', 32), $form)['status']);
        self::assertSame(404, $adapter->dispatch('POST', '/account/keys/revoke',
            true, 'application/x-www-form-urlencoded', '192.0.2.7',
            str_repeat('c', 32), $form)['status']);
        $dashboard = $adapter->dispatch('GET', '/account', true, null,
            '192.0.2.7', str_repeat('c', 32), null);
        self::assertSame(200, $dashboard['status']);
        self::assertStringNotContainsString('action="/account/keys/issue"', $dashboard['body']);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnauthenticatedRequestsNeverCallOwnerKeyService(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $this->createStub(LoginAttemptLimiter::class),
            $this->createStub(SecurityEventRecorder::class),
        );
        $overview = $this->createMock(OwnerOverviewProvider::class);
        $overview->expects(self::never())->method('forUser');
        $manager = $this->createMock(OwnerCredentialManager::class);
        $manager->expects(self::never())->method('issue');
        $manager->expects(self::never())->method('revoke');
        $adapter = new AccountBrowserAdapter($session, $login, $accounts, $overview, $manager);

        self::assertSame(401, $adapter->dispatch('POST', '/account/keys/issue',
            true, null, '192.0.2.7', str_repeat('a', 32), null)['status']);
        self::assertSame(401, $adapter->dispatch('POST', '/account/keys/revoke',
            true, null, '192.0.2.7', str_repeat('a', 32), null)['status']);
        self::assertSame(403, $adapter->dispatch('GET', '/account',
            false, null, '192.0.2.7', str_repeat('a', 32), null)['status']);
        self::assertSame(404, $adapter->dispatch('GET', '/account/unlisted',
            true, null, '192.0.2.7', str_repeat('a', 32), null)['status']);
        self::assertTrue(session_destroy());
    }
}
