<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\View\TwigAdminPageRenderer;
use OPGateway\View\TwigRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Each independent admin page must have both accessible small-screen cards
 * and desktop tables, without leaking data from unrelated queues.
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

    public function testEachDetailPageHasExactlyOneResponsiveListing(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        foreach (['users', 'owners', 'clients', 'pending_ips', 'approved_ips'] as $section) {
            $html = $view->section($section, self::CSRF, 'Administrator', self::data(), true);
            self::assertSame(1, substr_count($html, 'class="opg-record-grid"'), $section);
            self::assertSame(1, substr_count($html, 'opg-desktop-table'), $section);
            self::assertSame(1, substr_count($html, 'role="listitem"'), $section);
            self::assertSame(2, substr_count($html, 'data-opg-record-details'), $section);
            self::assertStringContainsString('id="opg-record-dialog"', $html);
            self::assertStringContainsString('id="opg-provision-dialog"', $html);
            self::assertStringContainsString('id="opg-confirm-dialog"', $html);
            self::assertStringContainsString('aria-label="Sign out"', $html);
            self::assertStringNotContainsString('</i> Sign out', $html);
            self::assertStringNotContainsString('Workspace overview</h1>', $html);
            self::assertStringContainsString('aria-current="page"', $html);
            preg_match_all('/\\bid="([^"]+)"/', $html, $ids);
            self::assertCount(count(array_unique($ids[1])), $ids[1], $section);
        }
    }

    public function testActionsArePresentedOnlyOnTheirCorrectPages(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        foreach ([
            'users' => ['approve_user'],
            'owners' => ['create_client'],
            'clients' => ['disable_client', 'request_ip'],
            'pending_ips' => ['approve_ip'],
            'approved_ips' => ['revoke_ip'],
        ] as $section => $actions) {
            $html = $view->section($section, self::CSRF, 'Administrator', self::data(), true);
            foreach ($actions as $action) {
                self::assertStringContainsString('name="action" value="' . $action . '"', $html);
            }
            self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $html);
        }
        $users = $view->section('users', self::CSRF, 'Administrator', self::data(), true);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $users);
        self::assertStringNotContainsString('<script>alert(1)</script>', $users);

        $owners = $view->section('owners', self::CSRF, 'Administrator', self::data(), true);
        self::assertStringContainsString('id="opg-mobile-create_client-9"', $owners);
        self::assertStringContainsString('id="opg-desktop-create_client-9"', $owners);
        $clients = $view->section('clients', self::CSRF, 'Administrator', self::data(), true);
        self::assertStringContainsString('id="opg-mobile-request_ip-15"', $clients);
        self::assertStringContainsString('id="opg-desktop-request_ip-15"', $clients);
    }

    public function testReadOnlyPagesNeverIncludeMutationForms(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        foreach (['overview', 'users', 'owners', 'clients', 'pending_ips', 'approved_ips'] as $section) {
            $html = $view->section($section, self::CSRF, 'Administrator', self::data(), false);
            self::assertStringNotContainsString('action="/admin/review"', $html, $section);
            self::assertStringNotContainsString('action="/admin/provision"', $html, $section);
        }
    }

    public function testProgressiveEnhancementDoesNotAddPostedFormFields(): void
    {
        $view = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        foreach (['users', 'owners', 'clients', 'pending_ips', 'approved_ips'] as $section) {
            $html = $view->section($section, self::CSRF, 'Administrator', self::data(), true);

            preg_match_all('~<form\\b[^>]*action="/admin/(review|provision)"[^>]*>(.*?)</form>~s',
                $html, $forms, PREG_SET_ORDER);
            self::assertNotEmpty($forms, $section);
            foreach ($forms as $form) {
                preg_match_all('/\\bname="([^"]+)"/', $form[2], $fieldMatches);
                $names = $fieldMatches[1];
                sort($names);
                $expected = $form[1] === 'provision'
                    ? ['_csrf', 'action', 'target_id', 'value']
                    : ['_csrf', 'action', 'target_id'];
                sort($expected);
                self::assertSame($expected, $names, $section);
            }
        }

        $owners = $view->section('owners', self::CSRF, 'Administrator', self::data(), true);
        self::assertSame(2, substr_count($owners, 'data-opg-provision'));
        $clients = $view->section('clients', self::CSRF, 'Administrator', self::data(), true);
        self::assertSame(2, substr_count($clients, 'data-opg-provision'));
        self::assertSame(2, substr_count($clients, 'data-confirm-target="Mobile client name"'));
        $ips = $view->section('approved_ips', self::CSRF, 'Administrator', self::data(), true);
        self::assertSame(2, substr_count($ips, 'data-confirm-target="192.0.2.15"'));

        $disabled = $view->section('owners', self::CSRF, 'Administrator', self::data(), false);
        self::assertStringNotContainsString('data-opg-provision', $disabled);
        self::assertStringContainsString('data-opg-record-details', $disabled);
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
