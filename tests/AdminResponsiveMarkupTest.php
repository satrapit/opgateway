<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\View\TwigAdminPageRenderer;
use OPGateway\View\TwigRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Presentation contracts: mobile records and desktop tables must use the
 * same already-authorized data and preserve action/CSRF semantics.
 */
final class AdminResponsiveMarkupTest extends TestCase
{
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    /** @return array<string, array<int, array<string, int|string>>> */
    private static function data(): array
    {
        return [
            'users' => [['id' => 4, 'email' => '<script>alert(1)</script>']],
            'owners' => [['id' => 9, 'email' => 'owner@example.test']],
            'clients' => [[
                'id' => 15, 'label' => 'Mobile client name',
                'owner_email' => 'owner@example.test', 'status' => 'active',
            ]],
            'ips' => [[
                'id' => 19, 'address' => '2001:db8::1234',
                'client_label' => 'Mobile client name',
            ]],
            'approved_ips' => [[
                'id' => 20, 'address' => '192.0.2.15',
                'client_label' => 'Mobile client name',
            ]],
        ];
    }

    public function testAllFiveQueuesProvideCardsAndDesktopTables(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $html = $view->dashboard(self::CSRF, 'Administrator', self::data(), true);

        self::assertSame(5, substr_count($html, 'class="opg-record-grid"'));
        self::assertSame(5, substr_count($html, 'opg-desktop-table'));
        self::assertSame(5, substr_count($html, 'role="listitem"'));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('aria-current="location"', $html);

        foreach ([
            'approve_user', 'create_client', 'disable_client',
            'request_ip', 'approve_ip', 'revoke_ip',
        ] as $action) {
            self::assertStringContainsString('name="action" value="' . $action . '"', $html);
        }

        // Both responsive presentations send only the existing strict form names.
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $html);
        self::assertStringContainsString('name="target_id" value="15"', $html);
        self::assertStringContainsString('name="value"', $html);
        self::assertStringContainsString('data-lucide="shield-x"', $html);
    }

    public function testReadOnlyViewDoesNotExposeMutatingFormsInEitherLayout(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $html = $view->dashboard(self::CSRF, 'Administrator', self::data(), false);

        self::assertSame(5, substr_count($html, 'class="opg-record-grid"'));
        self::assertStringNotContainsString('action="/admin/review"', $html);
        self::assertStringNotContainsString('action="/admin/provision"', $html);
        self::assertStringContainsString('Read-only mode', $html);
    }

    public function testLoginIncludesLocallyBundledIconThemeControl(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $html = $view->login(self::CSRF);

        self::assertStringContainsString('data-lucide="sun"', $html);
        self::assertStringContainsString('data-lucide="moon"', $html);
        self::assertStringContainsString('data-lucide="monitor"', $html);
        self::assertStringContainsString('src="/assets/admin/app.js"', $html);
        self::assertStringContainsString('href="/assets/admin/app.css"', $html);
        self::assertStringNotContainsString('https://cdn.', $html);
    }
}
