<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\PasswordSessionStamp;
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
