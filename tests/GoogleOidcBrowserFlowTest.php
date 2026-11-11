<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Google\GoogleOidcProvider;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Http\GoogleOidcBrowserController;
use OPGateway\Http\OwnerOverviewProvider;
use OPGateway\Identity\AuthenticationSessionVersion;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PdoGoogleIdentityStore;
use OPGateway\Mail\GoogleIdentityConfirmationMailer;
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

    /** @return array{0:GoogleOidcBrowserController,1:PDO,2:BrowserSession,3:LoginAccountRepository,4:GoogleOidcProvider,5:GoogleIdentityConfirmationMailer} */
    private function harness(
        bool $userExists = true, ?SecurityEventRecorder $eventRecorder = null,
    ): array
    {
        $passwordHash = password_hash('account-password-123', PASSWORD_DEFAULT);
        $vault = new SealedSecret(str_repeat('a', 64));
        $db = new class($passwordHash, $vault->encrypt('google-secret', 'google:oauth'), $userExists)
            extends PDO {
            public ?string $googleSub = null;
            public ?string $googleEmail = null;
            public int $version = 1;
            public ?array $confirmation = null;
            public bool $transaction = false;
            public bool $identityConflict = false;
            public bool $googleConfigured = true;
            public string $userStatus = 'active';
            public string $userEmail = 'owner@example.com';
            public string $lastSql = '';
            public array $lastParams = [];

            public function __construct(
                public ?string $passwordHash,
                public string $clientSecretCipher,
                public bool $userExists,
            ) {
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
            public function lastInsertId(?string $name = null): string|false
            {
                return '17';
            }
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this, $query) extends PDOStatement {
                    private int $rows = 1;
                    public function __construct(private PDO $db, private string $query)
                    {
                    }
                    public function execute(?array $params = null): bool
                    {
                        $this->db->lastSql = $this->query;
                        $this->db->lastParams = $params ?? [];
                        if (str_starts_with(trim($this->query), 'INSERT INTO users')) {
                            $this->db->userExists = true;
                            $this->db->passwordHash = null;
                        }
                        if (str_starts_with(trim($this->query), 'INSERT INTO google_identities')) {
                            if ($this->db->identityConflict) {
                                throw new \PDOException('duplicate Google subject', 23000);
                            }
                            $this->db->googleSub = (string) $this->db->lastParams[1];
                            $this->db->googleEmail = (string) $this->db->lastParams[2];
                        }
                        if (str_starts_with(trim($this->query), 'INSERT INTO google_identity_confirmations')) {
                            $this->db->confirmation = [
                                'id' => 4, 'user_id' => 17,
                                'google_sub' => $this->db->lastParams[1],
                                'google_email' => $this->db->lastParams[2],
                                'token_hash' => $this->db->lastParams[4],
                                'consumed' => false,
                            ];
                        }
                        if (str_contains($this->query,
                            'UPDATE google_identity_confirmations SET consumed_at')
                            && $this->db->confirmation !== null
                            && (str_contains($this->query, 'WHERE id = ?')
                                || str_contains($this->query, 'WHERE token_hash = ?'))) {
                            $this->db->confirmation['consumed'] = true;
                        }
                        if (str_starts_with(trim($this->query), 'DELETE FROM google_identities')) {
                            $this->rows = $this->db->googleSub === null ? 0 : 1;
                            $this->db->googleSub = null;
                            $this->db->googleEmail = null;
                        }
                        if (str_contains($this->query, 'auth_session_version = ?')) {
                            $this->db->version = (int) $this->db->lastParams[0];
                        } elseif (str_contains($this->query, 'auth_session_version = auth_session_version + 1')) {
                            $this->db->version++;
                        }
                        if (str_contains($this->query,
                            'UPDATE users SET email_verified_at = COALESCE')) {
                            $this->db->version = (int) $this->db->lastParams[0];
                        }
                        return true;
                    }
                    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
                    {
                        return str_contains($this->query, 'FROM settings')
                            && $this->db->googleConfigured ? [
                            'google.client_id' => GoogleOidcBrowserFlowTest::CLIENT_ID,
                            'google.client_secret' => $this->db->clientSecretCipher,
                        ] : [];
                    }
                    public function fetchColumn(int $column = 0): mixed
                    {
                        if (str_contains($this->query, 'SELECT id FROM users WHERE email')) {
                            return $this->db->userExists ? 17 : false;
                        }
                        if (str_contains($this->query, 'SELECT email FROM users')) {
                            return $this->db->userExists ? $this->db->userEmail : false;
                        }
                        if (str_contains($this->query, 'SELECT password_hash FROM users')) {
                            return $this->db->userStatus === 'active'
                                ? $this->db->passwordHash : false;
                        }
                        if (str_contains($this->query, 'g.google_sub = ?')
                            && str_contains($this->query, 'g.user_id = ?')) {
                            return $this->db->userStatus === 'active'
                                && ($this->db->lastParams[0] ?? null) === $this->db->googleSub
                                && ($this->db->lastParams[1] ?? null) === 17 ? 1 : false;
                        }
                        return false;
                    }
                    public function fetch(
                        int $mode = PDO::FETCH_DEFAULT,
                        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                        int $cursorOffset = 0,
                    ): mixed {
                        if (str_contains($this->query, 'WHERE g.google_sub = ?')
                            && $this->db->googleSub !== null
                            && ($this->db->lastParams[0] ?? '') === $this->db->googleSub) {
                            return [
                                'id' => 17, 'status' => $this->db->userStatus,
                                'email_verified_at' => '2026-01-01',
                                'approved_at' => '2026-01-01',
                                'auth_session_version' => $this->db->version,
                            ];
                        }
                        if (str_contains($this->query, 'SELECT g.google_email')) {
                            return $this->db->googleEmail === null ? false : [
                                'google_email' => $this->db->googleEmail,
                                'password_hash' => $this->db->passwordHash,
                            ];
                        }
                        if (str_contains($this->query, 'SELECT email, auth_session_version')) {
                            return $this->db->userExists ? [
                                'email' => 'owner@example.com',
                                'auth_session_version' => $this->db->version,
                            ] : false;
                        }
                        if (str_contains($this->query, 'FROM google_identity_confirmations c')
                            && $this->db->confirmation !== null
                            && !$this->db->confirmation['consumed']
                            && hash_equals($this->db->confirmation['token_hash'],
                                (string) ($this->db->lastParams[0] ?? ''))) {
                            return [
                                ...$this->db->confirmation,
                                'email' => $this->db->userEmail,
                                'status' => $this->db->userStatus,
                                'approved_at' => '2026-01-01',
                                'auth_session_version' => $this->db->version,
                            ];
                        }
                        return false;
                    }
                    public function rowCount(): int
                    {
                        return $this->rows;
                    }
                };
            }
        };

        $session = new BrowserSession();
        $session->start(true);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);
        $accounts->method('currentSessionStamp')->willReturnCallback(
            static fn (): string => AuthenticationSessionVersion::stamp($db->version),
        );
        $accounts->method('findEligibleByEmail')->willReturnCallback(
            static fn (): array => [
                'id' => 17, 'password_hash' => $db->passwordHash,
                'session_stamp' => AuthenticationSessionVersion::stamp($db->version),
            ],
        );
        $limits = $this->createStub(LoginAttemptLimiter::class);
        $limits->method('allow')->willReturn(true);
        $events = $eventRecorder ?? $this->createStub(SecurityEventRecorder::class);
        $login = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()), $limits, $events,
        );
        $overview = $this->createStub(OwnerOverviewProvider::class);
        $overview->method('eligibleEmail')->willReturn('owner@example.com');
        $provider = new class implements GoogleOidcProvider {
            public string $email = 'owner@example.com';
            public bool $verified = true;
            public int $calls = 0;
            public ?bool $recentAuthenticationRequired = null;
            public function exchange(
                string $code, string $clientId, string $clientSecret,
                string $origin, string $verifier, string $nonce,
                bool $requireRecentAuthentication,
            ): array {
                $this->calls++;
                $this->recentAuthenticationRequired = $requireRecentAuthentication;
                if ($code !== 'test-code' || $clientId !== GoogleOidcBrowserFlowTest::CLIENT_ID
                    || $clientSecret !== 'google-secret'
                    || $origin !== 'https://opgateway.test') {
                    throw new \RuntimeException('Unexpected callback data.');
                }
                return ['sub' => 'google-sub-123', 'email' => $this->email,
                    'email_verified' => $this->verified];
            }
        };
        $mailer = new class implements GoogleIdentityConfirmationMailer {
            public ?string $recipient = null;
            public ?string $token = null;
            public bool $fail = false;
            public function sendGoogleIdentityConfirmation(
                string $email, string $token, string $origin,
            ): void {
                $this->recipient = $email;
                $this->token = $token;
                if ($this->fail) {
                    throw new \RuntimeException('Test mail transport unavailable.');
                }
            }
        };
        $controller = new GoogleOidcBrowserController(
            $session, $accounts, $login, $overview,
            new PdoGoogleIdentityStore($db), new PdoGoogleOAuthSettings($db, $vault),
            $provider, 'https://opgateway.test', $mailer, null, $events,
        );
        return [$controller, $db, $session, $accounts, $provider, $mailer];
    }

    private static function state(string $location): string
    {
        $query = parse_url($location, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        return $params['state'];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreviouslyLinkedSubjectSignsInAndCallbackIsOneTime(): void
    {
        [$controller, $db, $session, $accounts, $provider] = $this->harness();
        $db->googleSub = 'google-sub-123';
        $db->googleEmail = 'owner@example.com';
        $start = $controller->dispatch('GET', '/account/google/start', null, null,
            '192.0.2.3', str_repeat('a', 32), '');
        $state = self::state($start['location']);
        $callback = $controller->dispatch('GET', '/account/google/callback', null, null,
            '192.0.2.3', str_repeat('b', 32), 'state=' . $state . '&code=test-code');
        self::assertSame(303, $callback['status']);
        self::assertSame('/account', $callback['location']);
        self::assertSame(17, $session->currentUserId($accounts));
        $replay = $controller->dispatch('GET', '/account/google/callback', null, null,
            '192.0.2.3', str_repeat('b', 32), 'state=' . $state . '&code=test-code');
        self::assertSame(403, $replay['status']);
        self::assertSame(1, $provider->calls);
        self::assertFalse($provider->recentAuthenticationRequired);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPasswordConfirmedLinkAndUnlinkRotateSessions(): void
    {
        [$controller, $db, $session, $accounts] = $this->harness();
        $session->authenticate(17, AuthenticationSessionVersion::stamp($db->version));
        $request = static fn (string $csrf): string => http_build_query([
            '_csrf' => $csrf, 'current_password' => 'account-password-123',
        ]);
        $start = $controller->dispatch('POST', '/account/google/link',
            'application/x-www-form-urlencoded', $request($session->csrfToken()),
            '192.0.2.3', str_repeat('a', 32), '');
        $state = self::state($start['location']);
        $linked = $controller->dispatch('GET', '/account/google/callback', null, null,
            '192.0.2.3', str_repeat('a', 32), 'state=' . $state . '&code=test-code');
        self::assertSame('/account/security', $linked['location']);
        self::assertSame('google-sub-123', $db->googleSub);
        self::assertSame(17, $session->currentUserId($accounts));
        $unlink = $controller->dispatch('POST', '/account/google/unlink',
            'application/x-www-form-urlencoded', $request($session->csrfToken()),
            '192.0.2.3', str_repeat('a', 32), '');
        self::assertSame(303, $unlink['status']);
        self::assertSame('/account/login', $unlink['location']);
        self::assertNull($db->googleSub);
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRollbackEmailCollisionDoesNotCreateOrJoinAnIdentity(): void
    {
        [$controller, $db, $session, $accounts, $provider, $mailer] = $this->harness();
        $start = $controller->dispatch('GET', '/account/google/start', null, null,
            '192.0.2.3', str_repeat('a', 32), '');
        $state = self::state($start['location']);
        $response = $controller->dispatch('GET', '/account/google/callback', null, null,
            '192.0.2.3', str_repeat('a', 32), 'state=' . $state . '&code=test-code');
        self::assertSame(403, $response['status']);
        self::assertStringContainsString('Google account not connected', $response['body']);
        self::assertNull($mailer->recipient);
        self::assertNull($mailer->token);
        self::assertNull($db->googleSub);
        self::assertNull($session->currentUserId($accounts));
        self::assertSame(1, $provider->calls);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnverifiedProviderEmailNeverReachesIdentityStorage(): void
    {
        [$controller, $db, $session, $accounts, $provider] = $this->harness(false);
        $provider->verified = false;
        $start = $controller->dispatch('GET', '/account/google/start', null, null,
            '192.0.2.3', str_repeat('a', 32), '');
        $response = $controller->dispatch('GET', '/account/google/callback', null, null,
            '192.0.2.3', str_repeat('a', 32),
            'state=' . self::state($start['location']) . '&code=test-code');
        self::assertSame(403, $response['status']);
        self::assertFalse($db->userExists);
        self::assertNull($db->googleSub);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRollbackUnknownSubjectDoesNotCreateGoogleOnlyUser(): void
    {
        [$controller, $db, $session, $accounts] = $this->harness(false);
        $start = $controller->dispatch('GET', '/account/google/start', null, null,
            '192.0.2.3', str_repeat('a', 32), '');
        $response = $controller->dispatch('GET', '/account/google/callback', null, null,
            '192.0.2.3', str_repeat('a', 32),
            'state=' . self::state($start['location']) . '&code=test-code');
        self::assertSame(403, $response['status']);
        self::assertStringContainsString('Google account not connected', $response['body']);
        self::assertFalse($db->userExists);
        self::assertIsString($db->passwordHash);
        self::assertNull($db->googleSub);
        self::assertNull($session->currentUserId($accounts));
        self::assertTrue(session_destroy());
    }

    public function testGoogleOnlyAccountCannotUnlinkItsLastMethod(): void
    {
        [, $db] = $this->harness();
        $db->googleSub = 'google-sub-123';
        $db->googleEmail = 'owner@example.com';
        $db->passwordHash = null;
        self::assertFalse((new PdoGoogleIdentityStore($db))->unlink(17));
        self::assertSame('google-sub-123', $db->googleSub);
        self::assertFalse($db->transaction);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDisabledGoogleProviderFailsClosedBeforeRedirect(): void
    {
        [$controller, $db, , , $provider] = $this->harness();
        $db->googleConfigured = false;
        $response = $controller->dispatch('GET', '/account/google/start', null, null,
            '192.0.2.3', str_repeat('a', 32), '');
        self::assertSame(503, $response['status']);
        self::assertSame(0, $provider->calls);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSubjectAlreadyLinkedElsewhereCannotBeLinked(): void
    {
        [$controller, $db, $session, $accounts] = $this->harness();
        $session->authenticate(17, AuthenticationSessionVersion::stamp($db->version));
        $start = $controller->dispatch('POST', '/account/google/link',
            'application/x-www-form-urlencoded', http_build_query([
                '_csrf' => $session->csrfToken(),
                'current_password' => 'account-password-123',
            ]), '192.0.2.3', str_repeat('a', 32), '');
        $db->identityConflict = true;
        $response = $controller->dispatch('GET', '/account/google/callback', null, null,
            '192.0.2.3', str_repeat('b', 32),
            'state=' . self::state($start['location']) . '&code=test-code');
        self::assertSame(409, $response['status']);
        self::assertNull($db->googleSub);
        self::assertSame(17, $session->currentUserId($accounts));
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRollbackNeverIssuesCollisionConfirmation(): void
    {
        [$controller, $db, , , , $mailer] = $this->harness();
        $mailer->fail = true;
        $start = $controller->dispatch('GET', '/account/google/start', null, null,
            '192.0.2.3', str_repeat('a', 32), '');
        $response = $controller->dispatch('GET', '/account/google/callback', null, null,
            '192.0.2.3', str_repeat('b', 32),
            'state=' . self::state($start['location']) . '&code=test-code');
        self::assertSame(403, $response['status']);
        self::assertNull($db->confirmation);
        self::assertNull($mailer->recipient);
        self::assertNull($db->googleSub);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSuspendedUserCannotConsumeMailboxConfirmation(): void
    {
        [$controller, $db, $session] = $this->harness();
        $token = str_repeat('a', 64);
        $db->confirmation = [
            'id' => 4, 'user_id' => 17, 'google_sub' => 'google-sub-123',
            'google_email' => 'owner@example.com',
            'token_hash' => hash('sha256', $token), 'consumed' => false,
        ];
        $db->userStatus = 'disabled';
        $response = $controller->dispatch('POST', '/account/google/confirm',
            'application/x-www-form-urlencoded', http_build_query([
                '_csrf' => $session->csrfToken(), 'token' => $token,
            ]), '192.0.2.3', str_repeat('c', 32), '');
        self::assertSame(403, $response['status']);
        self::assertNull($db->googleSub);
        self::assertFalse($db->confirmation['consumed']);
        self::assertTrue(session_destroy());
    }

    public function testSuspendedUserCannotUnlinkGoogleDuringEligibilityRace(): void
    {
        [, $db] = $this->harness();
        $db->googleSub = 'google-sub-123';
        $db->googleEmail = 'owner@example.com';
        $db->userStatus = 'disabled';
        self::assertFalse((new PdoGoogleIdentityStore($db))->unlink(17));
        self::assertSame('google-sub-123', $db->googleSub);
        self::assertFalse($db->transaction);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMailboxConfirmationRejectsAccountEmailChangedAfterIssuance(): void
    {
        [$controller, $db, $session] = $this->harness();
        $token = str_repeat('b', 64);
        $db->confirmation = [
            'id' => 4, 'user_id' => 17, 'google_sub' => 'google-sub-123',
            'google_email' => 'owner@example.com',
            'token_hash' => hash('sha256', $token), 'consumed' => false,
        ];
        $db->userEmail = 'changed@example.com';
        $response = $controller->dispatch('POST', '/account/google/confirm',
            'application/x-www-form-urlencoded', http_build_query([
                '_csrf' => $session->csrfToken(), 'token' => $token,
            ]), '192.0.2.3', str_repeat('c', 32), '');
        self::assertSame(403, $response['status']);
        self::assertNull($db->googleSub);
        self::assertFalse($db->confirmation['consumed']);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAuditFailureCannotLeaveUsableGoogleStepUpGrant(): void
    {
        $events = new class implements SecurityEventRecorder {
            public function record(string $eventCode, string $requestId): void
            {
                throw new \RuntimeException('test audit unavailable');
            }
        };
        [$controller, $db, $session, $accounts, $provider] = $this->harness(true, $events);
        $db->googleSub = 'google-sub-123';
        $db->googleEmail = 'owner@example.com';
        $session->authenticate(17, AuthenticationSessionVersion::stamp($db->version));
        $start = $controller->dispatch('POST', '/account/google/verify',
            'application/x-www-form-urlencoded', http_build_query([
                '_csrf' => $session->csrfToken(), 'action' => 'api_key_issue',
            ]), '192.0.2.3', str_repeat('a', 32), '');
        try {
            $controller->dispatch('GET', '/account/google/callback', null, null,
                '192.0.2.3', str_repeat('b', 32),
                'state=' . self::state($start['location']) . '&code=test-code');
            self::fail('Audit failure must stop Google step-up.');
        } catch (\RuntimeException $error) {
            self::assertSame('test audit unavailable', $error->getMessage());
        }
        self::assertTrue($provider->recentAuthenticationRequired);
        self::assertSame(17, $session->currentUserId($accounts));
        self::assertFalse($session->consumeGoogleAction(17, 'api_key_issue'));
        self::assertTrue(session_destroy());
    }
}
