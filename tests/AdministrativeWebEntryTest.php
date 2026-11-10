<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AdministrativeWebEntry;
use PHPUnit\Framework\TestCase;

final class AdministrativeWebEntryTest extends TestCase
{
    public function testUiIsDisabledByDefault(): void
    {
        $entry = new AdministrativeWebEntry([]);
        self::assertSame(404, $entry->handle('GET', '/admin/login', [])['status']);
        self::assertSame(404, $entry->handle('GET', '/admin', [])['status']);
    }

    public function testRejectsUntrustedHttpsAndWrongHostButNotIp(): void
    {
        $entry = new AdministrativeWebEntry([
            'ADMIN_UI_ENABLED' => 'true',
            'APP_URL' => 'https://opgateway.example.org',
            'LOGIN_RATE_LIMIT_KEY' => str_repeat('a', 64),
        ]);
        self::assertSame(404, $entry->handle('GET', '/admin/login', [
            'HTTPS' => 'off',
            'HTTP_HOST' => 'opgateway.example.org',
            'REMOTE_ADDR' => '192.0.2.4',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])['status']);
        self::assertSame(404, $entry->handle('GET', '/admin/login', [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'other.example.org',
            'REMOTE_ADDR' => '192.0.2.5',
        ])['status']);
    }

    public function testMissingHostIsRejectedBeforeDatabaseAccess(): void
    {
        $entry = new AdministrativeWebEntry([
            'ADMIN_UI_ENABLED' => 'true',
            'APP_URL' => 'https://opgateway.example.org',
            'LOGIN_RATE_LIMIT_KEY' => str_repeat('a', 64),
        ]);
        self::assertSame(404, $entry->handle('GET', '/admin/login', [
            'HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.45',
        ])['status']);
    }

    public function testFailsClosedForMissingKeyBeforeDatabaseAccess(): void
    {
        $entry = new AdministrativeWebEntry([
            'ADMIN_UI_ENABLED' => 'true',
            'ADMIN_UI_ALLOWED_IP' => '192.0.2.4',
            'APP_URL' => 'https://opgateway.example.org',
        ]);
        self::assertSame(503, $entry->handle('GET', '/admin/login',
            ['HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.4'])['status']);
    }
}
