<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\ApiCredentialService;
use OPGateway\Security\ApiCredentialStore;
use OPGateway\Security\ApiKey;
use OPGateway\Security\ClientIpAccessPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiCredentialServiceTest extends TestCase
{
    private function service(): array
    {
        $store = new class implements ApiCredentialStore {
            public array $entries = [];
            public bool $allowIssuance = true;
            public bool $revoked = false;
            public function createForOwner(int $actorUserId, int $clientId, string $prefix, string $digest): bool
            {
                if (!$this->allowIssuance || $actorUserId !== 9 || $clientId !== 7) {
                    return false;
                }
                $this->entries[$prefix] = ['client_id' => $clientId, 'digest' => $digest];
                return true;
            }
            public function findActiveByPrefix(string $prefix): ?array
            {
                return $this->revoked ? null : ($this->entries[$prefix] ?? null);
            }
            public function revokeForOwner(int $actorUserId, int $clientId, int $credentialId): bool
            {
                if ($actorUserId !== 9 || $clientId !== 7 || $credentialId !== 1) {
                    return false;
                }
                $this->revoked = true;
                return true;
            }
        };
        $ips = new class implements ClientIpAccessPolicy {
            public function allows(int $clientId, string $sourceIp): bool
            {
                return $clientId === 7 && $sourceIp === '192.0.2.10';
            }
        };
        return [new ApiCredentialService($store, $ips), $store];
    }

    public function testIssueAuthenticateIpDenyAndRevocation(): void
    {
        [$service, $store] = $this->service();
        $key = $service->issue(9, 7);
        self::assertMatchesRegularExpression('/^opg_[0-9a-f]{16}\.[0-9a-f]{64}$/', $key);
        $prefix = explode('.', $key)[0];
        self::assertNotSame($key, $store->entries[$prefix]['digest']);
        self::assertSame(7, $service->authenticate($key, '192.0.2.10'));
        self::assertNull($service->authenticate($key, '192.0.2.11'));

        $parts = explode('.', $key);
        $parts[1] = str_repeat('0', 64);
        self::assertNull($service->authenticate(implode('.', $parts), '192.0.2.10'));
        self::assertTrue($service->revoke(9, 7, 1));
        self::assertNull($service->authenticate($key, '192.0.2.10'));
    }

    public function testUnknownAndInvalidTokensDenied(): void
    {
        [$service] = $this->service();
        self::assertNull($service->authenticate('', '192.0.2.10'));
        self::assertNull($service->authenticate(ApiKey::issue()['key'], '192.0.2.10'));
        self::assertNull($service->authenticate('opg_fake.' . str_repeat('0', 64), '192.0.2.10'));
    }

    public function testIssuanceDeniedForUnauthorizedActor(): void
    {
        [$service] = $this->service();
        $this->expectException(RuntimeException::class);
        $service->issue(11, 7);
    }

    public function testGeneratedKeysAreDistinct(): void
    {
        $first = ApiKey::issue();
        $second = ApiKey::issue();
        self::assertNotSame($first['key'], $second['key']);
        self::assertSame(strlen($first['digest']), 64);
    }
}
