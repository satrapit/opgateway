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

    public function testTwigViewIsGatedAndFailsForMissingAssets(): void
    {
        $environment = file_get_contents(dirname(__DIR__) . '/.env.example');
        $entry = file_get_contents(dirname(__DIR__) . '/src/Http/AdministrativeWebEntry.php');
        self::assertIsString($environment);
        self::assertIsString($entry);
        self::assertStringContainsString('ADMIN_TWIG_UI_ENABLED=false', $environment);
        self::assertStringContainsString('ADMIN_ACTIONS_ENABLED=false', $environment);
        self::assertStringContainsString('admin.twig_assets_unavailable', $entry);
        self::assertStringContainsString('TwigAdminPageRenderer', $entry);
    }
}
