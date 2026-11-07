<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\EmailVerificationRepository;
use OPGateway\Identity\EmailVerificationService;
use OPGateway\Identity\EmailVerificationToken;
use PHPUnit\Framework\TestCase;

final class EmailVerificationServiceTest extends TestCase
{
    public function testTokenIsHashedBeforePersistenceAndSingleUse(): void
    {
        $repo = new class implements EmailVerificationRepository {
            public string $storedHash = '';
            public int $expiresIn = 0;
            public bool $used = false;

            public function issue(int $userId, string $tokenHash, int $ttlSeconds): void
            {
                if ($userId !== 12) {
                    throw new \LogicException('Unexpected test user.');
                }
                $this->storedHash = $tokenHash;
                $this->expiresIn = $ttlSeconds;
            }

            public function consume(string $tokenHash): bool
            {
                if ($this->used || !hash_equals($this->storedHash, $tokenHash)) {
                    return false;
                }
                $this->used = true;
                return true;
            }
        };
        $service = new EmailVerificationService($repo);
        $rawToken = $service->issueForUser(12);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rawToken);
        self::assertNotSame($rawToken, $repo->storedHash);
        self::assertSame(EmailVerificationToken::hash($rawToken), $repo->storedHash);
        self::assertSame(1800, $repo->expiresIn);
        self::assertTrue($service->verify($rawToken));
        self::assertFalse($service->verify($rawToken));
        self::assertFalse($service->verify('invalid'));
    }
}
