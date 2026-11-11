<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\BrowserGetPath;
use OPGateway\View\TwigRenderer;
use PHPUnit\Framework\TestCase;

/** Creation flows are full pages; actual DB policies live in existing services. */
final class AdminCreationPagesTest extends TestCase
{
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testCreateClientPageShowsApprovedOwnersAndNoAutomaticActivation(): void
    {
        $twig = new TwigRenderer(dirname(__DIR__) . '/templates');
        $html = $twig->render('admin/create-client.html.twig', [
            'active_section' => 'clients',
            'section_title' => 'Create API client',
            'administrator_label' => 'Administrator',
            'csrf_token' => self::CSRF,
            'owners' => [['id' => 42, 'email' => '<owner@example.test>']],
            'clients' => [],
        ]);
        self::assertStringContainsString('action="/admin/clients/create"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="target_id"', $html);
        self::assertStringContainsString('value="42"', $html);
        self::assertStringContainsString('&lt;owner@example.test&gt;', $html);
        self::assertStringNotContainsString('<owner@example.test>', $html);
        self::assertStringContainsString('New clients start disabled', $html);
    }

    public function testRequestIpPageOnlyListsActiveClients(): void
    {
        $twig = new TwigRenderer(dirname(__DIR__) . '/templates');
        $html = $twig->render('admin/request-ip.html.twig', [
            'active_section' => 'pending_ips',
            'section_title' => 'Request IP',
            'administrator_label' => 'Administrator',
            'csrf_token' => self::CSRF,
            'owners' => [],
            'clients' => [
                ['id'=>6,'label'=>'Live','status'=>'active',
                    'owner_email'=>'a@example.test'],
                ['id'=>7,'label'=>'Disabled','status'=>'disabled',
                    'owner_email'=>'b@example.test'],
            ],
        ]);
        self::assertStringContainsString('action="/admin/ips/request"', $html);
        self::assertStringContainsString('value="6"', $html);
        self::assertStringNotContainsString('value="7"', $html);
        self::assertStringContainsString('name="value"', $html);
        self::assertStringContainsString('IPv4 or IPv6', $html);
    }

    public function testNewUrlsNormalizeTrailingSlashOnGetOnly(): void
    {
        foreach ([
            '/admin/users/invite', '/admin/clients/create',
            '/admin/ips/request', '/admin/settings/security',
            '/admin/captcha/image', '/account/captcha/image',
        ] as $path) {
            self::assertSame($path, BrowserGetPath::normalize('GET', $path . '/'));
            self::assertSame($path . '/', BrowserGetPath::normalize('POST', $path . '/'));
        }
    }

    public function testNavigationIncludesIconAndNoAllSettingsPages(): void
    {
        $layout = file_get_contents(dirname(__DIR__) . '/templates/layouts/admin.html.twig');
        self::assertIsString($layout);
        self::assertStringContainsString('#opg-icon-settings', $layout);
        self::assertStringContainsString('#opg-icon-chevron-down', $layout);
        self::assertStringContainsString('x-on:click="toggleSettingsMenu"', $layout);
        self::assertStringContainsString('/admin/settings/security', $layout);
        self::assertStringContainsString('/admin/settings/smtp', $layout);
        self::assertStringNotContainsString('All settings', $layout);
        self::assertFileDoesNotExist(dirname(__DIR__) . '/templates/admin/settings.html.twig');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/templates/admin/login-settings.html.twig');
    }
}
