<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\View\TwigAdminPageRenderer;
use OPGateway\View\TwigRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Static-only asset caching and independent navigation scrolling.
 * Layout tests complement, but never replace, real-browser checks.
 */
final class AdminStaticAssetPerformanceTest extends TestCase
{
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testSidebarScrollAreaIsSeparateFromFixedLogoHeader(): void
    {
        $pages = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $html = $pages->section('clients', self::CSRF, 'Administrator');
        self::assertStringContainsString('class="opg-sidebar-header flex h-20 shrink-0', $html);
        self::assertStringContainsString('class="opg-sidebar-scroll min-h-0 flex-1 overflow-y-auto"', $html);
        self::assertStringContainsString('role="region" aria-label="Sidebar navigation and information"', $html);
        self::assertLessThan(strpos($html, 'opg-sidebar-scroll'), strpos($html, 'opg-sidebar-header'));
        self::assertLessThan(strpos($html, 'Administrator sections'), strpos($html, 'opg-sidebar-scroll'));
    }

    public function testIconsArePrepaintedSvgWithCacheBustedLocalAssetUrls(): void
    {
        $pages = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $html = $pages->login(self::CSRF);
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('<use href="/assets/admin/icons.svg?v=', $html);
        self::assertStringNotContainsString('data-lucide=', $html);
        self::assertMatchesRegularExpression('~rel="preload" href="/assets/admin/icons\\.svg\\?v=[0-9a-f]{16}" as="image"~', $html);
        self::assertMatchesRegularExpression('~rel="preload" href="/assets/admin/app\\.js\\?v=[0-9a-f]{16}" as="script"~', $html);
        self::assertMatchesRegularExpression('~src="/assets/admin/theme-init\\.js\\?v=[0-9a-f]{16}"~', $html);
        self::assertStringNotContainsString('https://cdn.', $html);
    }

    public function testIconSpriteCoversAllReferencedIconsAndHasNoScript(): void
    {
        $root = dirname(__DIR__);
        $sprite = file_get_contents($root . '/frontend/src/icons.svg');
        self::assertIsString($sprite);
        self::assertSame(20, substr_count($sprite, '<symbol id="opg-icon-'));
        self::assertStringNotContainsString('<script', $sprite);
        foreach (['menu', 'x', 'moon', 'sun', 'monitor', 'log-out',
            'ellipsis-vertical', 'eye', 'shield-alert', 'shield-x', 'ban', 'plus'] as $icon) {
            self::assertStringContainsString('id="opg-icon-' . $icon . '"', $sprite);
        }
    }

    public function testCachePolicyAppliesOnlyInsideAdminStaticDirectory(): void
    {
        $root = dirname(__DIR__);
        $rules = file_get_contents($root . '/frontend/src/cache.htaccess');
        $deployment = file_get_contents($root . '/.cpanel.yml');
        self::assertIsString($rules);
        self::assertIsString($deployment);
        self::assertStringContainsString('public, max-age=31536000, immutable', $rules);
        self::assertStringContainsString('Header always unset Cache-Control', $rules);
        self::assertStringContainsString('public/assets/admin/.htaccess', $deployment);
        self::assertStringNotContainsString('/bin/cp -f public/.htaccess', $deployment);
    }

    public function testAssetVersionChangesWithContent(): void
    {
        $root = dirname(__DIR__);
        $sprite = $root . '/public/assets/admin/icons.svg';
        $hash = substr(hash_file('sha256', $sprite), 0, 16);
        $pages = new TwigAdminPageRenderer(new TwigRenderer($root . '/templates'));
        $html = $pages->login(self::CSRF);
        self::assertStringContainsString('/assets/admin/icons.svg?v=' . $hash, $html);
    }
}
