<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use PHPUnit\Framework\TestCase;

final class AdminAssetPolicyTest extends TestCase
{
    public function testOnlyExpectedBuiltFilesAreCopied(): void
    {
        $deployment = file_get_contents(dirname(__DIR__) . '/.cpanel.yml');
        self::assertIsString($deployment);
        foreach (['public/assets/admin/app.css', 'public/assets/admin/app.js',
            'public/assets/admin/theme-init.js', 'public/assets/admin/icons.svg',
            'public/assets/admin/.htaccess'] as $path) {
            self::assertStringContainsString($path, $deployment);
        }
        self::assertStringContainsString('/bin/cp -f public/index.php', $deployment);
        self::assertStringNotContainsString('/bin/cp -f public/.htaccess', $deployment);
        self::assertStringContainsString('/bin/cp -f public/assets/admin/.htaccess', $deployment);
        self::assertStringNotContainsString('/bin/cp -f src/', $deployment);
        self::assertStringNotContainsString('/bin/cp -f vendor/', $deployment);
        self::assertStringNotContainsString('/bin/cp -f templates/', $deployment);
    }

    public function testSingleTwigAdminViewRequiresBundledAssetsAndDbOnlyEnvironment(): void
    {
        $environment = file_get_contents(dirname(__DIR__) . '/.env.example');
        $entry = file_get_contents(dirname(__DIR__) . '/src/Http/AdministrativeWebEntry.php');
        self::assertIsString($environment);
        self::assertIsString($entry);
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $environment, $matches);
        self::assertSame(
            ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'],
            $matches[1],
        );
        self::assertStringNotContainsString('_ENABLED', $environment);
        self::assertStringNotContainsString('_ENABLED', $entry);
        self::assertStringContainsString('admin.twig_assets_unavailable', $entry);
        self::assertStringContainsString('new TwigAdminPageRenderer(', $entry);
    }
}
