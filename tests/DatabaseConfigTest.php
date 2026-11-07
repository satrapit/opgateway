<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Database\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    private const VALID = [
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
        'DB_NAME' => 'arsamnet_opgateway',
        'DB_USER' => 'arsamnet_gateway',
        'DB_PASSWORD' => 'do-not-display',
    ];

    public function testBuildsDsnWithoutExposingPassword(): void
    {
        $config = DatabaseConfig::fromEnvironment(self::VALID);
        self::assertSame('mysql:host=localhost;port=3306;dbname=arsamnet_opgateway;charset=utf8mb4', $config->dsn());
        self::assertStringNotContainsString('do-not-display', $config->dsn());
    }

    public function testRejectsMissingSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromEnvironment(['DB_HOST' => 'localhost']);
    }

    public function testRejectsDsnInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromEnvironment([...self::VALID, 'DB_HOST' => 'localhost;dbname=other']);
    }

    public function testRejectsInvalidPort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromEnvironment([...self::VALID, 'DB_PORT' => '65536']);
    }

    public function testRejectsInvalidDatabaseName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromEnvironment([...self::VALID, 'DB_NAME' => 'other;password=bad']);
    }
}
