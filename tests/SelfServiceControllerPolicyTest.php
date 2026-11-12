<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Clients\ClientManagementService;
use OPGateway\Clients\OwnerIntegrationService;
use OPGateway\Http\AccountViews;
use OPGateway\Http\OwnerOverviewProvider;
use OPGateway\Http\SelfServiceBrowserController;
use OPGateway\Identity\AuthenticationSessionVersion;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Provider\PdoProviderAccount;
use OPGateway\Security\CredentialActionLimiter;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\SealedSecret;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SelfServiceControllerPolicyTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGoogleOnlyOwnerCanSaveProviderCredentialsAfterBoundVerification(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $stamp = AuthenticationSessionVersion::stamp(2);
        $session->authenticate(17, $stamp);
        $session->grantGoogleAction(17, 'provider_credentials');
        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('currentSessionStamp')->willReturn($stamp);
        $accounts->method('findEligibleByEmail')->willReturn([
            'id' => 17, 'password_hash' => null, 'session_stamp' => $stamp,
        ]);
        $pdo = new class extends PDO {
            public bool $transaction = false;
            public array $queries = [];
            public function __construct()
            {
            }
            public function beginTransaction(): bool
            {
                $this->transaction = true;
                return true;
            }
            public function commit(): bool
            {
                $this->transaction = false;
                return true;
            }
            public function rollBack(): bool
            {
                $this->transaction = false;
                return true;
            }
            public function inTransaction(): bool
            {
                return $this->transaction;
            }
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this, $query) extends PDOStatement {
                    public function __construct(private PDO $db, private string $sql)
                    {
                    }
                    public function execute(?array $params = null): bool
                    {
                        $this->db->queries[] = [$this->sql, $params ?? []];
                        return true;
                    }
                    public function rowCount(): int
                    {
                        return 1;
                    }
                };
            }
        };
        $limiter = $this->createStub(CredentialActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $events = $this->createStub(SecurityEventRecorder::class);
        $integration = new OwnerIntegrationService(
            $pdo, new PdoProviderAccount($pdo, new SealedSecret(str_repeat('a', 64))),
            new ClientManagementService($pdo), $limiter, $events,
        );
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $this->createStub(LoginAttemptLimiter::class), $events,
        );
        $overview = $this->createStub(OwnerOverviewProvider::class);
        $overview->method('eligibleEmail')->willReturn('google-only@example.test');
        $controller = new SelfServiceBrowserController(
            $session, $accounts, null, $integration, 'https://opgateway.test',
            $login, $overview,
        );
        $response = $controller->dispatch(
            'POST', '/account/provider/save', 'application/x-www-form-urlencoded',
            http_build_query([
                '_csrf' => $session->csrfToken(), 'username' => 'reseller-user',
                'password' => 'upstream-secret', 'current_password' => '',
            ]), '192.0.2.7', str_repeat('a', 32), '',
        );
        self::assertSame(303, $response['status']);
        self::assertSame('/account', $response['location']);
        self::assertCount(1, $pdo->queries);
        self::assertStringContainsString('INSERT INTO provider_connections', $pdo->queries[0][0]);
        self::assertSame('reseller-user', $pdo->queries[0][1][0]);
        self::assertNotSame('upstream-secret', $pdo->queries[0][1][1]);
        self::assertFalse($pdo->transaction);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOptionalDependenciesFailClosedWhenNotConfigured(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $controller = new SelfServiceBrowserController(
            $session, $accounts, null, null, 'https://opgateway.test',
        );
        foreach ([
            '/account/register', '/account/verify', '/account/verify/resend',
            '/account/forgot-password', '/account/reset-password',
            '/account/provider/save', '/account/clients/create', '/account/ips/request',
        ] as $path) {
            self::assertTrue(SelfServiceBrowserController::route($path));
            self::assertSame(404, $controller->dispatch(
                'GET', $path, null, null, '192.0.2.10', str_repeat('f', 32), '',
            )['status'], $path);
        }
        self::assertFalse(SelfServiceBrowserController::route('/v1/domains'));
        self::assertFalse(SelfServiceBrowserController::route('/admin'));
        self::assertTrue(session_destroy());
    }

    public function testPublicRegistrationMarkupHasCsrfAndNoSecrets(): void
    {
        $token = str_repeat('a', 64);
        $html = AccountViews::register($token);
        self::assertStringContainsString('action="/account/register"', $html);
        self::assertStringContainsString('action="/account/verify/resend"', $html);
        self::assertStringContainsString('name="_csrf" value="' . $token . '"', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringNotContainsString('password_hash', $html);
    }

    public function testGoogleOnlyRegistrationDoesNotOfferUnavailablePasswordSignup(): void
    {
        $html = AccountViews::register(str_repeat('a', 64), true, false);
        self::assertStringContainsString('/account/google/start', $html);
        self::assertStringNotContainsString('action="/account/register"', $html);
        self::assertStringNotContainsString('action="/account/verify/resend"', $html);
        self::assertStringNotContainsString('or register with a password', $html);
    }

    public function testRegistrationConfirmationIsCheckedOnServer(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Http/SelfServiceBrowserController.php');
        self::assertIsString($source);
        self::assertStringContainsString("'password_confirmation'", $source);
        self::assertStringContainsString("hash_equals(\$form['password'], \$form['password_confirmation'])", $source);
    }

    public function testEmailLinkUsesLocalAutoPostWithAccessibleFallback(): void
    {
        $html = AccountViews::verifyForm(str_repeat('a', 64), str_repeat('b', 64));
        self::assertStringContainsString('action="/account/verify"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('data-opg-auto-submit="email-verification"', $html);
        self::assertStringContainsString('Verify email', $html);
        self::assertStringNotContainsString('https://cdn.', $html);
    }

    public function testEmailVerificationSuccessDirectsUserToSignIn(): void
    {
        $html = AccountViews::verified(true);
        self::assertStringContainsString('Email verified', $html);
        self::assertStringContainsString(
            'Your email address has been verified successfully. You can now sign in.',
            $html,
        );
        self::assertStringContainsString('href="/account/login"', $html);
        self::assertStringContainsString('>Sign in</a>', $html);
    }
}
