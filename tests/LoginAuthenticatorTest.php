<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class LoginAuthenticatorTest extends TestCase
{
    public function testApprovedVerifiedUserCanAuthenticate(): void
    {
        $hasher = new PasswordHasher();
        $password = 'correct-horse-long-sample';
        $repo = new class($hasher->hash($password)) implements LoginAccountRepository {
            public function __construct(private string $hash)
            {
            }

            public function findEligibleByEmail(string $email): ?array
            {
                return $email === 'test@example.com'
                    ? ['id' => 17, 'password_hash' => $this->hash]
                    : null;
            }

            public function isEligible(int $userId): bool
            {
                return $userId === 17;
            }
            public function currentSessionStamp(int $userId): ?string
            {
                return $userId === 17
                    ? \OPGateway\Identity\AuthenticationSessionVersion::stamp(1) : null;
            }
        };

        $auth = new LoginAuthenticator($repo, $hasher);
        self::assertSame(17, $auth->authenticate(' TEST@Example.Com ', $password));
        self::assertSame($repo->currentSessionStamp(17),
            $auth->authenticateWithStamp('test@example.com', $password)['stamp']);
        self::assertNull($auth->authenticate('test@example.com', 'incorrect'));
        self::assertNull($auth->authenticate('missing@example.com', $password));
        self::assertNull($auth->authenticate('not an email', $password));
        self::assertNull($auth->authenticate('test@example.com', str_repeat('X', 1025)));
    }

    public function testInvalidAccountIdIsRejected(): void
    {
        $repo = new class implements LoginAccountRepository {
            public function findEligibleByEmail(string $email): ?array
            {
                return ['id' => 0, 'password_hash' => password_hash('valid-example-password', PASSWORD_DEFAULT)];
            }

            public function isEligible(int $userId): bool
            {
                return false;
            }
            public function currentSessionStamp(int $userId): ?string
            {
                return null;
            }
        };
        self::assertNull((new LoginAuthenticator($repo, new PasswordHasher()))
            ->authenticate('test@example.com', 'valid-example-password'));
    }
}
