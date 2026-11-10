<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\View\TwigAdminPageRenderer;
use OPGateway\View\TwigRenderer;
use PHPUnit\Framework\TestCase;

/** Keep the small row-action surface, header heights and native dialog UX stable. */
final class AdminCompactUiTest extends TestCase
{
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testTwoHeadersShareTheSameFixedHeightAndRetainLogout(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $html = $view->section('clients', self::CSRF, 'Administrator', [
            'users' => [], 'owners' => [], 'ips' => [], 'approved_ips' => [], 'clients' => [],
        ]);
        self::assertStringContainsString('opg-sidebar-header flex h-20', $html);
        self::assertStringContainsString('opg-main-header sticky top-0 z-20 flex h-20', $html);
        self::assertStringContainsString('aria-label="Sign out"', $html);
        self::assertStringContainsString('action="/admin/logout"', $html);
    }

    public function testTableUsesOneAccessibleActionMenuPerRecord(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $html = $view->section('clients', self::CSRF, 'Administrator', [
            'users' => [], 'owners' => [], 'ips' => [], 'approved_ips' => [],
            'clients' => [[
                'id' => 13, 'label' => '<script>alert(1)</script>',
                'owner_email' => 'owner@example.test', 'status' => 'active',
            ]],
        ], true);
        self::assertSame(2, substr_count($html, 'data-opg-row-menu'));
        self::assertSame(2, substr_count($html, 'title="More options for &lt;script&gt;'));
        self::assertStringNotContainsString('title="More options for <script>', $html);
        self::assertSame(2, substr_count($html, 'name="action" value="disable_client"'));
        self::assertSame(2, substr_count($html, 'name="action" value="request_ip"'));
        self::assertStringNotContainsString('role="menu"', $html);
    }

    public function testDialogDismissalAndMenusNeedNoExternalCode(): void
    {
        $root = dirname(__DIR__);
        $menus = file_get_contents($root . '/frontend/src/js/row-menus.js');
        $dialogs = file_get_contents($root . '/frontend/src/js/admin-dialogs.js');
        self::assertIsString($menus);
        self::assertIsString($dialogs);
        self::assertStringContainsString("event.key !== 'Escape'", $menus);
        self::assertStringContainsString("event.target === dialog", $dialogs);
        self::assertStringContainsString("startedOutside && outside(event)", $dialogs);
        self::assertStringNotContainsString('fetch(', $menus . $dialogs);
        self::assertStringNotContainsString('.innerHTML', $menus . $dialogs);
    }
}
