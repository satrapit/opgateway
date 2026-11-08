<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\BrowserViews;
use PHPUnit\Framework\TestCase;

/**
 * Presentation security regression coverage for the Twig admin slice.
 * These tests require the approved offline Composer Twig install.
 */
final class TwigAdminViewsTest extends TestCase
{
    private const TOKEN = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testEscapesDangerousValuesAndPreservesValidCsrfFields(): void
    {
        $html = BrowserViews::dashboard(self::TOKEN, '<b>admin</b>', [
            'users' => [['id' => 12, 'email' => '"><script>alert(1)</script>']],
            'owners' => [],
            'clients' => [],
            'ips' => [],
            'approved_ips' => [],
        ], true);

        self::assertStringContainsString('&lt;b&gt;admin&lt;/b&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('name="_csrf" value="' . self::TOKEN . '"', $html);
        self::assertSame(1, substr_count($html, 'name="action" value="approve_user"'));
    }

    public function testReadOnlyStateDoesNotRenderMutationForms(): void
    {
        $html = BrowserViews::dashboard(self::TOKEN, 'Administrator', [
            'users' => [['id' => 1, 'email' => 'pending@example.com']],
            'owners' => [['id' => 2, 'email' => 'approved@example.com']],
            'clients' => [['id' => 3, 'owner_email' => 'approved@example.com',
                'label' => 'Client', 'status' => 'active']],
            'ips' => [['id' => 4, 'client_label' => 'Client',
                'address' => '192.0.2.1']],
            'approved_ips' => [['id' => 5, 'client_label' => 'Client',
                'address' => '192.0.2.2']],
        ]);

        self::assertStringContainsString('read-only', $html);
        self::assertStringNotContainsString('action="/admin/review"', $html);
        self::assertStringNotContainsString('action="/admin/provision"', $html);
        self::assertStringContainsString('Records shown · max 50', $html);
        self::assertStringNotContainsString('Total users', $html);
    }

    public function testAllExistingActionsKeepTheirStrictFormNames(): void
    {
        $html = BrowserViews::dashboard(self::TOKEN, 'Administrator', [
            'users' => [['id' => 1, 'email' => 'pending@example.com']],
            'owners' => [['id' => 2, 'email' => 'approved@example.com']],
            'clients' => [
                ['id' => 3, 'owner_email' => 'approved@example.com',
                    'label' => 'Inactive client', 'status' => 'disabled'],
                ['id' => 4, 'owner_email' => 'approved@example.com',
                    'label' => 'Active client', 'status' => 'active'],
            ],
            'ips' => [['id' => 5, 'client_label' => 'Active client',
                'address' => '192.0.2.1']],
            'approved_ips' => [['id' => 6, 'client_label' => 'Active client',
                'address' => '192.0.2.2']],
        ], true);

        foreach (['approve_user', 'activate_client', 'disable_client',
            'approve_ip', 'revoke_ip', 'create_client', 'request_ip'] as $action) {
            self::assertStringContainsString('name="action" value="' . $action . '"', $html);
        }
        self::assertStringNotContainsString('name="client_secret"', $html);
    }
}
