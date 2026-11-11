<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\SecurityEventRecorder;
use PHPUnit\Framework\TestCase;

final class GuardedLoginServiceTest extends TestCase
{
    public function testThrottlePreventsPasswordCheckAndRecordsSafeEvent(): void
    {
        $accounts = new class implements LoginAccountRepository {
            public int $reads = 0;
            public function findEligibleByEmail(string $email): ?array
            {
                $this->reads++;
                return null;
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
        $limiter = new class implements LoginAttemptLimiter {
            public function allow(string $sourceIp, string $email): bool
            {
                return false;
            }
        };
        $events = new class implements SecurityEventRecorder {
            public array $codes = [];
            public function record(string $eventCode, string $requestId): void
            {
                $this->codes[] = [$eventCode, $requestId];
            }
        };
        $service = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $limiter,
            $events,
        );
        self::assertNull($service->authenticate('x@example.com', 'not-a-secret', '192.0.2.1', str_repeat('a', 32)));
        self::assertSame(0, $accounts->reads);
        self::assertSame([['login_throttled', str_repeat('a', 32)]], $events->codes);
    }

    public function testDeniedLoginProducesGenericOutcome(): void
    {
        $accounts = new class implements LoginAccountRepository {
            public function findEligibleByEmail(string $email): ?array
            {
                return null;
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
        $limiter = new class implements LoginAttemptLimiter {
            public function allow(string $sourceIp, string $email): bool
            {
                return true;
            }
        };
        $events = new class implements SecurityEventRecorder {
            public array $codes = [];
            public function record(string $eventCode, string $requestId): void
            {
                $this->codes[] = $eventCode;
            }
        };
        $service = new GuardedLoginService(
            new LoginAuthenticator($accounts, new PasswordHasher()),
            $limiter,
            $events,
        );
        self::assertNull($service->authenticate('unknown@example.com', 'irrelevant', '192.0.2.1', str_repeat('b', 32)));
        self::assertSame(['login_denied'], $events->codes);
    }
}
