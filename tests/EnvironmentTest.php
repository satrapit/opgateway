<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Config\Environment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentTest extends TestCase
{
    public function testRejectsMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        Environment::load('/nonexistent/opgateway/test-env-file');
    }

    public function testAcceptsOnlyDatabaseConnectionValues(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'opg-env-');
        self::assertIsString($file);
        try {
            file_put_contents($file, implode("\n", [
                'DB_HOST=127.0.0.1', 'DB_PORT=3306', 'DB_NAME=opgateway',
                'DB_USER=local', 'DB_PASSWORD=test-only',
            ]));
            self::assertSame([
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
                'DB_NAME' => 'opgateway', 'DB_USER' => 'local',
                'DB_PASSWORD' => 'test-only',
            ], Environment::load($file));
        } finally {
            unlink($file);
        }
    }

    public function testRejectsObsoleteRuntimeFeatureFlags(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'opg-env-');
        self::assertIsString($file);
        try {
            file_put_contents($file, "DB_HOST=127.0.0.1\nADMIN_TWIG_UI_ENABLED=true\n");
            $this->expectException(RuntimeException::class);
            Environment::load($file);
        } finally {
            unlink($file);
        }
    }
}
