<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Config\Environment;
use OPGateway\Database\DatabaseConfig;
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

    public function testRejectsObsoleteConfigurationFlagsAndSecrets(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'opg-env-');
        self::assertIsString($file);
        try {
            foreach ([
                'ADMIN_TWIG_UI_ENABLED', 'ADMIN_ACTIONS_ENABLED',
                'ADMIN_ALLOWED_IPS', 'ALLOWED_IPS',
                'SMTP_HOST', 'OPENPROVIDER_USERNAME',
                'GOOGLE_CLIENT_SECRET', 'APP_KEY',
            ] as $obsolete) {
                file_put_contents($file, "DB_HOST=127.0.0.1\n$obsolete=example\n");
                try {
                    Environment::load($file);
                    self::fail("Obsolete .env key was accepted: $obsolete");
                } catch (RuntimeException $exception) {
                    self::assertSame('Only database connection keys are allowed.',
                        $exception->getMessage());
                }
            }
        } finally {
            unlink($file);
        }
    }

    public function testExampleContainsExactlyDatabaseConnectionKeys(): void
    {
        $example = file_get_contents(dirname(__DIR__) . '/.env.example');
        self::assertIsString($example);
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $example, $matches);
        self::assertSame(DatabaseConfig::ENV_KEYS, $matches[1]);
        self::assertCount(5, array_unique($matches[1]));
    }

    public function testPhpApplicationNeverReadsAlternativeProcessEnvironment(): void
    {
        // A new getenv()/$_ENV fallback could silently resurrect old
        // ADMIN_IP / feature flags. Keep the database .env as the only source.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:getenv|putenv|parse_ini_file)\s*\(|\$_ENV\s*\[/',
                $source, $file->getFilename(),
            );
        }
    }
}
