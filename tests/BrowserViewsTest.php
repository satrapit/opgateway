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
}
