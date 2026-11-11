<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Google\GoogleOidcProvider;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Http\GoogleOidcBrowserController;
use OPGateway\Http\OwnerOverviewProvider;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PasswordSessionStamp;
use OPGateway\Identity\PdoGoogleIdentityStore;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\SealedSecret;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GoogleOidcBrowserFlowTest extends TestCase
{
    public const CLIENT_ID = '123456789012-abcdefghijk.apps.googleusercontent.com';

    /**
     * Fake database protects real MySQL while enforcing the expected
     * prepared statements and password-hash-bound sessions.
     * @return array{0:GoogleOidcBrowserController,1:PDO,2:BrowserSession,3:LoginAccountRepository,4:GoogleOidcProvider}
     */
    private function harness(): array
    {
        $passwordHash = password_hash('account-password-123', PASSWORD_DEFAULT);
        $vault = new SealedSecret(str_repeat('a', 64));
        $db = new class($passwordHash, $vault->encrypt('google-secret', 'google:oauth'))
            extends PDO {
            public ?string $googleSub = null;
            public ?string $googleEmail = null;
            public string $lastSql = '';
            public array $lastParams = [];

            public function __construct(
                public string $passwordHash,
                public string $clientSecretCipher,
            ) {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this, $query) extends PDOStatement {
                    public function __construct(
                        private PDO $db, private string $query,
                    ) {
                    }

                    public function execute(?array $params = null): bool
                    {
                        $this->db->lastSql = $this->query;
                        $this->db->lastParams = $params ?? [];
                        if (str_starts_with($this->query, 'INSERT INTO google_identities')) {
                            $this->db->googleSub = $this->db->lastParams[1];
                            $this->db->googleEmail = $this->db->lastParams[2];
                        }
                        if (str_starts_with($this->query, 'DELETE FROM google_identities')) {
                            $this->db->googleSub = null;
                            $this->db->googleEmail = null;
                        }
                        return true;
                    }

                    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
                    {
                        if (str_contains($this->query, 'FROM settings')) {
                            return [
                                'google.client_id' => GoogleOidcBrowserFlowTest::CLIENT_ID,
                                'google.client_secret' => $this->db->clientSecretCipher,
                            ];
                        }
                        return [];
                    }

                    public function fetchColumn(int $column = 0): mixed
                    {
                        if (str_contains($this->query, 'FROM google_identities')) {
                            return $this->db->googleEmail ?: false;
                        }
                        return false;
                    }

                    public function fetch(
                        int $mode = PDO::FETCH_DEFAULT,
                        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                        int $cursorOffset = 0,
                    ): mixed {
                        if (str_contains($this->query, 'JOIN users u')
                            && $this->db->googleSub !== null
                            && ($this->db->lastParams[0] ?? '') === $this->db->googleSub) {
                            return ['id' => 17, 'password_hash' => $this->db->passwordHash];
                        }
                        return false;
                    }
                };
            }
        };

        $session = new BrowserSession();
        $session->start(true);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $accounts->method('currentSessionStamp')->willReturn(
            PasswordSessionStamp::fromHash($passwordHash),
        );
        $accounts->method('findEligibleByEmail')->willReturn(
            ['id' => 17, 'password_hash' => $passwordHash],
        );
        $limits = $this->createStub(LoginAttemptLimiter::class);
        $limits->method('allow')->willReturn(true);
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $limits, $this->createStub(SecurityEventRecorder::class),
        );
        $overview = $this->createStub(OwnerOverviewProvider::class);
        $overview->method('eligibleEmail')->willReturn('owner@example.com');
        $provider = new class implements GoogleOidcProvider {
            public string $email = 'owner@example.com';
            public int $calls = 0;

            public function exchange(
                string $code, string $clientId, string $clientSecret,
                string $origin, string $verifier, string $nonce,
            ): array {
                $this->calls++;
                if ($code !== 'test-code' || $clientId !==
                    '123456789012-abcdefghijk.apps.googleusercontent.com'
                    || $clientSecret !== 'google-secret'
                    || $origin !== 'https://opgateway.test') {
                    throw new \RuntimeException('Unexpected callback data.');
                }
                return ['sub' => 'google-sub-123', 'email' => $this->email];
            }
        };
        $controller = new GoogleOidcBrowserController(
            $session, $accounts, $login, $overview,
            new PdoGoogleIdentityStore($db),
            new PdoGoogleOAuthSettings($db, $vault),
            $provider, 'https://opgateway.test',
        );
        return [$controller, $db, $session, $accounts, $provider];
    }

    /** @return array{state:string} */
    private static function stateFromRedirect(string $location): array
    {
        self::assertStringStartsWith('https://accounts.google.com/', $location);
        $query = parse_url($location, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        return ['state' => $params['state']];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreviouslyLinkedGoogleSubjectSignsInWithExistingLocalPasswordHash(): void
    {
        [$controller, $db, $session, $accounts, $provider] = $this->harness();
        $db->googleSub = 'google-sub-123';
        $db->googleEmail = 'owner@example.com';

        $start = $controller->dispatch('GET', '/account/google/start',
            null, null, '192.0.2.3', str_repeat('a', 32), '');
        self::assertSame(303, $start['status']);
        $state = self::stateFromRedirect($start['location'])['state'];

        $callback = $controller->dispatch('GET', '/account/google/callback',
            null, null, '192.0.2.3', str_repeat('b', 32),
            'state=' . $state . '&code=test-code');
        self::assertSame(303, $callback['status']);
        self::assertSame('/account', $callback['location']);
        self::assertSame(1, $provider->calls);
        self::assertSame(17, $session->currentUserId($accounts));

        // A consumed callback cannot be replayed.
        $replay = $controller->dispatch('GET', '/account/google/callback',
            null, null, '192.0.2.3', str_repeat('b', 32),
            'state=' . $state . '&code=test-code');
        self::assertSame(403, $replay['status']);
        self::assertSame(1, $provider->calls);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPasswordConfirmedLinkAndUnlinkDoNotReplaceLocalPassword(): void
    {
        [$controller, $db, $session, $accounts, $provider] = $this->harness();
        $session->authenticate(17, PasswordSessionStamp::fromHash($db->passwordHash));
        $csrf = $session->csrfToken();
        $request = static fn (string $token) => http_build_query([
            '_csrf' => $token, 'current_password' => 'account-password-123',
        ]);

        $denied = $controller->dispatch('POST', '/account/google/link',
            'application/x-www-form-urlencoded', $request(str_repeat('0', 64)),
            '192.0.2.3', str_repeat('a', 32), '');
        self::assertSame(403, $denied['status']);

        $start = $controller->dispatch('POST', '/account/google/link',
            'application/x-www-form-urlencoded', $request($csrf),
            '192.0.2.3', str_repeat('a', 32), '');
        self::assertSame(303, $start['status']);
        $state = self::stateFromRedirect($start['location'])['state'];

        $linked = $controller->dispatch('GET', '/account/google/callback',
            null, null, '192.0.2.3', str_repeat('a', 32),
            'state=' . $state . '&code=test-code');
        self::assertSame(303, $linked['status']);
        self::assertSame('/account/google', $linked['location']);
        self::assertSame('google-sub-123', $db->googleSub);
        self::assertSame('owner@example.com', $db->googleEmail);
        self::assertSame(17, $session->currentUserId($accounts));

        $unlink = $controller->dispatch('POST', '/account/google/unlink',
            'application/x-www-form-urlencoded', $request($session->csrfToken()),
            '192.0.2.3', str_repeat('a', 32), '');
        self::assertSame(303, $unlink['status']);
        self::assertNull($db->googleSub);
        self::assertTrue(password_verify('account-password-123', $db->passwordHash));
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGoogleIdentityDoesNotLinkAutomaticallyFromMatchingEmail(): void
    {
        [$controller, $db, $session, $accounts, $provider] = $this->harness();
        $start = $controller->dispatch('GET', '/account/google/start',
            null, null, '192.0.2.3', str_repeat('a', 32), '');
        $state = self::stateFromRedirect($start['location'])['state'];
        $rejected = $controller->dispatch('GET', '/account/google/callback',
            null, null, '192.0.2.3', str_repeat('a', 32),
            'state=' . $state . '&code=test-code');
        self::assertSame(403, $rejected['status']);
        self::assertStringContainsString('Google account not connected', $rejected['body']);
        self::assertNull($db->googleSub);
        self::assertNull($session->currentUserId($accounts));
        self::assertTrue(session_destroy());
    }
}
