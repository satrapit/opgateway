<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\PasswordSessionStamp;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PasswordResetMailer;
use OPGateway\Identity\PasswordResetService;
use OPGateway\Identity\PdoLoginAccountRepository;
use OPGateway\Security\PdoPasswordResetLimiter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Changing the password hash denies previously authenticated browser access. */
final class SessionRevocationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPasswordChangeRevokesSessionAndInvalidatesItsCsrf(): void
    {
        $accounts = new class implements LoginAccountRepository {
            public string $hash = '';
            public bool $eligible = true;
            public function findEligibleByEmail(string $email): ?array
            {
                return ['id' => 17, 'password_hash' => $this->hash];
            }
            public function isEligible(int $userId): bool
            {
                return $this->eligible && $userId === 17;
            }
            public function currentSessionStamp(int $userId): ?string
            {
                return $this->isEligible($userId)
                    ? PasswordSessionStamp::fromHash($this->hash) : null;
            }
        };

        $accounts->hash = password_hash('first-password-123', PASSWORD_DEFAULT);
        $oldStamp = PasswordSessionStamp::fromHash($accounts->hash);
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(17, $oldStamp);
        $csrf = $session->csrfToken();
        self::assertSame(17, $session->currentUserId($accounts));

        $accounts->hash = password_hash('second-password-123', PASSWORD_DEFAULT);
        self::assertNotSame($oldStamp, PasswordSessionStamp::fromHash($accounts->hash));
        self::assertNull($session->currentUserId($accounts));
        self::assertFalse($session->csrfValid($csrf));
        self::assertArrayNotHasKey('opgateway_user_id', $_SESSION);
        self::assertArrayNotHasKey('opgateway_password_stamp', $_SESSION);
        self::assertNull($session->currentUserId($accounts));

        $session->authenticate(17, PasswordSessionStamp::fromHash($accounts->hash));
        self::assertSame(17, $session->currentUserId($accounts));
        $accounts->eligible = false;
        self::assertNull($session->currentUserId($accounts));
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnonymousProtectedPagePreservesLoginCsrf(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $csrf = $session->csrfToken();
        $accounts = $this->createMock(LoginAccountRepository::class);
        $accounts->expects(self::never())->method('currentSessionStamp');

        self::assertNull($session->currentUserId($accounts));
        self::assertTrue($session->csrfValid($csrf));
        self::assertNull($session->currentUserId($accounts));
        self::assertSame($csrf, $session->csrfToken());
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacySessionWithoutPasswordStampFailsClosed(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $_SESSION['opgateway_user_id'] = 17;
        $_SESSION['opgateway_auth_at'] = time();
        $_SESSION['opgateway_csrf'] = str_repeat('a', 64);
        $accounts = $this->createMock(LoginAccountRepository::class);
        $accounts->expects(self::never())->method('currentSessionStamp');
        self::assertNull($session->currentUserId($accounts));
        self::assertFalse($session->csrfValid(str_repeat('a', 64)));
        self::assertTrue(session_destroy());
    }

    public function testSuccessfulResetUpdatesHashAndInvalidatesOldStampAtomically(): void
    {
        $db = new class extends PDO {
            public string $passwordHash;
            public bool $inTx = false;
            public bool $tokenConsumed = false;

            public function __construct()
            {
                $this->passwordHash = password_hash('previous-password-123', PASSWORD_DEFAULT);
            }

            public function beginTransaction(): bool
            {
                $this->inTx = true;
                return true;
            }

            public function inTransaction(): bool
            {
                return $this->inTx;
            }

            public function commit(): bool
            {
                $this->inTx = false;
                return true;
            }

            public function rollBack(): bool
            {
                $this->inTx = false;
                return true;
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class ($this, $query) extends PDOStatement {
                    public function __construct(private PDO $db, private string $sql)
                    {
                    }
                    public function execute(?array $params = null): bool
                    {
                        if (str_contains($this->sql, 'UPDATE users SET password_hash = ?')) {
                            $this->db->passwordHash = $params[0];
                        }
                        if (str_contains($this->sql, 'UPDATE password_reset_tokens SET consumed_at')) {
                            $this->db->tokenConsumed = true;
                        }
                        return true;
                    }
                    public function fetchColumn(int $column = 0): mixed
                    {
                        return 17;
                    }
                    public function rowCount(): int
                    {
                        return 1;
                    }
                };
            }
        };
        $oldStamp = PasswordSessionStamp::fromHash($db->passwordHash);
        $mailer = new class implements PasswordResetMailer {
            public function sendPasswordReset(string $email, string $token, string $origin): void
            {
                throw new \LogicException('Reset completion must not send an email.');
            }
        };
        $service = new PasswordResetService(
            $db, new PasswordHasher(),
            new PdoPasswordResetLimiter($db, str_repeat('k', 32)), $mailer,
        );

        self::assertTrue($service->reset(str_repeat('a', 64), 'replacement-password-123'));
        self::assertTrue(password_verify('replacement-password-123', $db->passwordHash));
        self::assertTrue($db->tokenConsumed);
        self::assertFalse($db->inTx);
        self::assertNotSame($oldStamp, PasswordSessionStamp::fromHash($db->passwordHash));
    }

    public function testStoredHashStampLookupRequiresEligibleAccount(): void
    {
        $hash = password_hash('sample-password-123', PASSWORD_DEFAULT);
        $db = new class ($hash) extends PDO {
            public string $sql = '';
            public array $params = [];

            public function __construct(public string|false $hash)
            {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->sql = $query;
                return new class ($this) extends PDOStatement {
                    public function __construct(private PDO $db)
                    {
                    }
                    public function execute(?array $params = null): bool
                    {
                        $this->db->params = $params ?? [];
                        return true;
                    }
                    public function fetchColumn(int $column = 0): mixed
                    {
                        return $this->db->hash;
                    }
                };
            }
        };
        $accounts = new PdoLoginAccountRepository($db);
        self::assertSame(PasswordSessionStamp::fromHash($hash), $accounts->currentSessionStamp(17));
        self::assertSame([17], $db->params);
        self::assertStringContainsString("status = 'active'", $db->sql);
        self::assertStringContainsString('email_verified_at IS NOT NULL', $db->sql);
        self::assertStringContainsString('approved_at IS NOT NULL', $db->sql);
        $db->hash = false;
        self::assertNull($accounts->currentSessionStamp(17));
        self::assertNull($accounts->currentSessionStamp(0));
    }

    public function testOpaqueStampChangesWhenStoredHashChanges(): void
    {
        $hash = password_hash('sample-long-password', PASSWORD_DEFAULT);
        $stamp = PasswordSessionStamp::fromHash($hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stamp);
        self::assertNotSame($hash, $stamp);
        self::assertSame($stamp, PasswordSessionStamp::fromHash($hash));
        self::assertNotSame($stamp, PasswordSessionStamp::fromHash(
            password_hash('sample-long-password', PASSWORD_DEFAULT),
        ));
    }
}
