<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AccountWebEntry;
use PHPUnit\Framework\TestCase;

final class AccountWebEntryTest extends TestCase
{
    public function testAccountRoutesAreDisabledByDefault(): void
    {
        $entry = new AccountWebEntry([]);
        self::assertSame(404, $entry->handle('GET', '/account/login', [])['status']);
        self::assertSame(404, $entry->handle('GET', '/account', [])['status']);
        self::assertSame(404, $entry->handle('POST', '/account/keys/issue', [])['status']);
    }

    public function testDisabledTransportIsRejectedBeforeDatabaseAccess(): void
    {
        $entry = new AccountWebEntry(['ACCOUNT_UI_ENABLED' => 'true']);
        self::assertSame(404, $entry->handle('GET', '/account/login', ['HTTPS' => 'off'])['status']);
        self::assertSame(404, $entry->handle('GET', '/account/login',
            ['HTTPS' => '', 'HTTP_X_FORWARDED_PROTO' => 'https'])['status']);
    }

    public function testInvalidConfigurationFailsClosedBeforeConnecting(): void
    {
        $entry = new AccountWebEntry([
            'ACCOUNT_UI_ENABLED' => 'true',
            'ACCOUNT_KEYS_ENABLED' => 'true',
            'APP_URL' => 'https://opgateway.test',
        ]);
        self::assertSame(503, $entry->handle('GET', '/account/login',
            ['HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1'])['status']);
    }
    public function testHostHeaderCannotOverrideCanonicalAccountOrigin(): void
    {
        $entry = new AccountWebEntry([
            'ACCOUNT_UI_ENABLED' => 'true',
            'APP_URL' => 'https://opgateway.test',
            'LOGIN_RATE_LIMIT_KEY' => str_repeat('a', 64),
        ]);
        self::assertSame(404, $entry->handle('GET', '/account/login', [
            'HTTPS' => 'on', 'HTTP_HOST' => 'another.example.test',
            'REMOTE_ADDR' => '127.0.0.1',
        ])['status']);
    }


}
