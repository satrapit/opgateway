<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Http\BrowserViews;
use PHPUnit\Framework\TestCase;

final class BrowserViewsTest extends TestCase
{
    private const TOKEN = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testLoginRendersServerSideCsrfAndPasswordInput(): void
    {
        $html = BrowserViews::login(self::TOKEN);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="password"', $html);
        self::assertStringContainsString('action="/admin/login"', $html);
        self::assertStringContainsString('<meta name="referrer" content="same-origin">', $html);
        self::assertStringNotContainsString('Sign-in was not successful.', $html);
        self::assertStringContainsString('role="alert"', BrowserViews::login(self::TOKEN, true));
    }

    public function testDashboardEscapesUserControlledEmail(): void
    {
        $html = BrowserViews::dashboard(self::TOKEN, '<img src=x onerror=alert(1)>');
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('action="/admin/logout"', $html);
    }

    public function testMalformedCsrfIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BrowserViews::login('not-a-token');
    }

    public function testDashboardEscapesPendingReviewEntries(): void
    {
        $html = BrowserViews::dashboard(self::TOKEN, 'Administrator', [
            'users' => [['id' => 3, 'email' => '<script>alert(1)</script>']],
            'clients' => [['id' => 4, 'label' => '<img src=x onerror=alert(2)>',
                'owner_email' => 'user@example.com']],
            'ips' => [['id' => 5, 'client_label' => 'Cloud & Services',
                'address' => '2001:db8::1']],
        ]);

        self::assertStringContainsString('Verified users awaiting approval', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        self::assertStringContainsString('Cloud &amp; Services', $html);
        self::assertStringContainsString('2001:db8::1', $html);
        self::assertStringContainsString('read-only', $html);
        self::assertStringNotContainsString('name="approve_user"', $html);
    }

    public function testEmptyReviewQueuesAreClearlyDisplayed(): void
    {
        $html = BrowserViews::dashboard(self::TOKEN, 'Administrator', [
            'users' => [], 'clients' => [], 'ips' => [],
        ]);
        self::assertSame(4, substr_count($html, 'None awaiting review.'));
    }

    public function testReviewFormsAreShownOnlyWhenActionsEnabled(): void
    {
        $pending = [
            'users' => [['id' => 2, 'email' => 'review@example.com']],
            'clients' => [['id' => 3, 'label' => 'Test Client',
                'owner_email' => 'owner@example.com']],
            'ips' => [['id' => 4, 'client_label' => 'Test Client',
                'address' => '192.0.2.3']],
        ];

        $readOnly = BrowserViews::dashboard(self::TOKEN, 'Administrator', $pending);
        self::assertStringNotContainsString('action="/admin/review"', $readOnly);
        self::assertStringContainsString('read-only', $readOnly);

        $enabled = BrowserViews::dashboard(self::TOKEN, 'Administrator', $pending, true);
        self::assertSame(3, substr_count($enabled, 'action="/admin/review"'));
        self::assertStringContainsString('name="action" value="approve_user"', $enabled);
        self::assertStringContainsString('name="action" value="activate_client"', $enabled);
        self::assertStringContainsString('name="action" value="approve_ip"', $enabled);
        self::assertStringContainsString('name="_csrf" value="' . self::TOKEN . '"', $enabled);
        self::assertStringContainsString('name="target_id" value="4"', $enabled);
    }

    public function testApprovedIpCanBeRevokedAndActiveClientCanBeDisabled(): void
    {
        $pending = [
            'users' => [], 'owners' => [],
            'clients' => [['id' => 7, 'label' => 'Active', 'status' => 'active',
                'owner_email' => 'owner@example.com']],
            'ips' => [],
            'approved_ips' => [['id' => 10, 'client_label' => 'Active',
                'address' => '2001:db8::7']],
        ];
        $enabled = BrowserViews::dashboard(self::TOKEN, 'Admin', $pending, true);
        self::assertStringContainsString('name="action" value="disable_client"', $enabled);
        self::assertStringContainsString('name="action" value="revoke_ip"', $enabled);
        self::assertStringContainsString('2001:db8::7', $enabled);
        self::assertStringContainsString('Approved IP allowlist entries', $enabled);
        $readOnly = BrowserViews::dashboard(self::TOKEN, 'Admin', $pending);
        self::assertStringNotContainsString('name="action" value="disable_client"', $readOnly);
        self::assertStringNotContainsString('name="action" value="revoke_ip"', $readOnly);
    }

}
