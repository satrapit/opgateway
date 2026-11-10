<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Provider\PdoProviderAccount;
use OPGateway\Security\SealedSecret;
use PHPUnit\Framework\TestCase;

final class ProviderSettingsSecurityTest extends TestCase
{
    public function testWrongOwnerAndInvalidCredentialsNeverReachPdo(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $store = new PdoProviderAccount($pdo, new SealedSecret(str_repeat('ac', 32)));
        $this->expectException(\InvalidArgumentException::class);
        $store->save(7, "reseller\ninjected", 'password');
    }

    public function testInvalidClientIdDoesNotQuerySecretStore(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $store = new PdoProviderAccount($pdo, new SealedSecret(str_repeat('ac', 32)));
        self::assertNull($store->credentialsForClient(0));
    }
}
