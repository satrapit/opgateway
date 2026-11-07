<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\RegistrationData;
use OPGateway\Identity\RegistrationService;
use OPGateway\Identity\UserRepository;
use PHPUnit\Framework\TestCase;

final class RegistrationServiceTest extends TestCase
{
    public function testRegistrationStoresHashAndNormalizedEmailOnly(): void
    {
        $repo = new class implements UserRepository {
            public string $email = '';
            public string $hash = '';
            public function insertPending(string $email, string $passwordHash): int
            {
                $this->email = $email;
                $this->hash = $passwordHash;
                return 42;
            }
        };
        $hasher = new PasswordHasher();
        $password = 'A strong example passphrase 2026';
        $service = new RegistrationService($repo, $hasher);
        self::assertSame(42, $service->register(RegistrationData::fromStrings(' Test@Example.COM ', $password)));
        self::assertSame('test@example.com', $repo->email);
        self::assertNotSame($password, $repo->hash);
        self::assertTrue($hasher->verify($password, $repo->hash));
        self::assertFalse($hasher->verify('incorrect passphrase', $repo->hash));
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RegistrationData::fromStrings('not-an-email', 'A strong example passphrase 2026');
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RegistrationData::fromStrings('test@example.com', 'short');
    }

    public function testPasswordWithNulIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RegistrationData::fromStrings('test@example.com', "valid-password\0with-nul");
    }

    public function testOversizedPasswordIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RegistrationData::fromStrings('test@example.com', str_repeat('z', 1025));
    }
}
