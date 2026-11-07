<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\DoctrineConnectionFactory;
use PHPUnit\Framework\TestCase;

final class DoctrineAdapterContractTest extends TestCase
{
    public function testMissingDependencyFailsClosedWithoutSecrets(): void
    {
        if (class_exists(\Doctrine\DBAL\DriverManager::class)) {
            self::markTestSkipped('Doctrine is present in this test runtime.');
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Doctrine DBAL dependency unavailable.');
        DoctrineConnectionFactory::open(['DB_PASSWORD' => 'should-never-be-displayed']);
    }

    public function testDbalLoginRepositoryImplementsExistingInterface(): void
    {
        self::assertTrue(is_subclass_of(
            \OPGateway\Identity\DbalLoginAccountRepository::class,
            \OPGateway\Identity\LoginAccountRepository::class,
        ));
        self::assertTrue(is_subclass_of(
            \OPGateway\Identity\DbalUserRepository::class,
            \OPGateway\Identity\UserRepository::class,
        ));
    }
}
