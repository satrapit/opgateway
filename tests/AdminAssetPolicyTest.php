<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use PHPUnit\Framework\TestCase;

/** Static regression guards; browser/CSP behavior must also be tested live. */
final class AdminAssetPolicyTest extends TestCase
{
    public function testAdminCspDoesNotRequireEvalOrInlineJavaScript(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/public/index.php');
        self::assertIsString($source);
        self::assertStringContainsString("script-src 'self'; style-src 'self'", $source);
        self::assertStringNotContainsString("'unsafe-eval'", $source);
        self::assertStringContainsString("header('Referrer-Policy: same-origin');", $source);
    }

    public function testOnlyCompiledAssetsAreApprovedForPublicDeployment(): void
    {
        $deployment = file_get_contents(dirname(__DIR__) . '/.cpanel.yml');
        self::assertIsString($deployment);
        self::assertStringContainsString('public/assets/admin.css', $deployment);
        self::assertStringContainsString('public/assets/admin.js', $deployment);
        self::assertStringContainsString('public/assets/theme-init.js', $deployment);
        self::assertStringNotContainsString('/bin/cp -f src/', $deployment);
        self::assertStringNotContainsString('/bin/cp -f vendor/', $deployment);
        self::assertStringNotContainsString('/bin/cp -f frontend/', $deployment);
        self::assertStringNotContainsString('/bin/cp -f templates/', $deployment);
    }
}
