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
        self::assertSame(3, substr_count($html, 'None awaiting review.'));
    }

}
