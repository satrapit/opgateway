<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AccountViews;
use PHPUnit\Framework\TestCase;

/**
 * Owner and admin must share one TailAdmin/Twig design system and secure assets.
 * These markup tests complement, not replace, real mobile browser checks.
 */
final class AccountDesignSystemTest extends TestCase
{
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testLoginAndRegistrationUseSharedThemeAndLocalAssets(): void
    {
        foreach ([AccountViews::login(self::CSRF, false, true), AccountViews::register(self::CSRF)] as $html) {
            self::assertStringContainsString('src="/assets/admin/theme-init.js?v=', $html);
            self::assertStringContainsString('href="/assets/admin/app.css?v=', $html);
            self::assertStringContainsString('src="/assets/admin/app.js?v=', $html);
            self::assertStringContainsString('x-data="adminShell"', $html);
            self::assertStringContainsString('data-theme-icon="system"', $html);
            self::assertStringContainsString('data-theme-icon="light"', $html);
            self::assertStringContainsString('data-theme-icon="dark"', $html);
            self::assertStringContainsString('opg-primary-button', $html);
            self::assertStringNotContainsString('<style>', $html);
            self::assertStringNotContainsString('https://cdn.', $html);
        }
        self::assertStringContainsString('href="/account/register"', AccountViews::login(self::CSRF, false, true));
    }

    public function testDashboardSharesShellAndEscapesSensitiveMetadata(): void
    {
        $overview = [
            'email' => '<script>alert(1)</script>@example.test',
            'clients' => [['id' => 17, 'label' => '<img src=x onerror=alert(1)>', 'status' => 'active']],
            'credentials' => [], 'ips' => [],
        ];
        $html = AccountViews::dashboard(self::CSRF, $overview, true, true, [
            'configured' => true, 'username' => '"><script>alert(1)</script>',
        ]);
        self::assertStringContainsString('opg-sidebar-header flex h-20', $html);
        self::assertStringContainsString('opg-main-header sticky top-0 z-20 flex h-20', $html);
        self::assertStringContainsString('aria-label="Account sections"', $html);
        self::assertStringContainsString('href="/account/clients"', $html);
        self::assertStringContainsString('href="/account/provider"', $html);
        self::assertStringContainsString('action="/account/logout"', $html);
        $clientsPage = AccountViews::dashboard(self::CSRF, $overview, true, true, [], 'clients');
        self::assertStringContainsString('action="/account/keys/issue"', $clientsPage);
        $providerPage = AccountViews::dashboard(self::CSRF, $overview, true, true, [
            'configured' => true, 'username' => '"><script>alert(1)</script>',
        ], 'provider');
        self::assertStringContainsString('action="/account/provider/save"', $providerPage);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $clientsPage);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('password_cipher', $html);
        self::assertStringNotContainsString('upstream_token', $html);
        self::assertStringNotContainsString('name="password" value=', $html);
        self::assertStringNotContainsString('<style>', $html);
        self::assertStringNotContainsString('id="opg-record-dialog"', $html);
        self::assertStringNotContainsString('id="opg-provision-dialog"', $html);
    }

    public function testConfirmationAndOneTimeKeyUseSameThemeWithoutLeakingKeyInUrl(): void
    {
        $verify = AccountViews::verifyForm(self::CSRF, str_repeat('a', 64));
        self::assertStringContainsString('opg-primary-button', $verify);
        self::assertStringContainsString('action="/account/verify"', $verify);
        $accepted = AccountViews::registrationAccepted();
        self::assertStringContainsString('opg-panel', $accepted);

        $key = 'opg_' . str_repeat('a', 16) . '.' . str_repeat('b', 64);
        $page = AccountViews::issued($key);
        self::assertSame(1, substr_count($page, $key));
        self::assertStringContainsString('<meta name="referrer" content="no-referrer">', $page);
        self::assertStringContainsString('select-all break-all', $page);
        self::assertStringNotContainsString('?key=', $page);
        self::assertStringNotContainsString('localStorage', $page);
    }

    public function testOwnerHtmlCspAndSharedRendererAreConfiguredWithoutFeatureFlags(): void
    {
        $root = dirname(__DIR__);
        $entry = file_get_contents($root . '/src/Http/AccountWebEntry.php');
        $renderer = file_get_contents($root . '/src/View/TwigRenderer.php');
        $ui = file_get_contents($root . '/src/Http/AccountViews.php');
        self::assertIsString($entry);
        self::assertIsString($renderer);
        self::assertIsString($ui);
        self::assertStringContainsString("style-src 'self'; script-src 'self'", $entry);
        self::assertStringContainsString("frame-src https://www.google.com/recaptcha/", $entry);
        self::assertStringContainsString('account|layouts|components', $renderer);
        self::assertStringNotContainsString('unsafe-inline', $entry);
        self::assertStringNotContainsString('style>', $ui);
        self::assertStringNotContainsString('BrowserViews', $ui);
    }
}
