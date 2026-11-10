<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AccountWebEntry;
use PHPUnit\Framework\TestCase;

final class AccountWebEntryTest extends TestCase
{
    public function testOwnerRoutesRequireTrustedHttpsAndCanonicalHost(): void
    {
        $entry = new AccountWebEntry([]);
        self::assertSame(404, $entry->handle('GET', '/account/login', ['HTTPS' => 'off'])['status']);
        self::assertSame(404, $entry->handle('GET', '/account/login', [
            'HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'opgateway.test', 'SERVER_NAME' => 'opgateway.test',
        ])['status']);
        self::assertSame(404, $entry->handle('GET', '/account/login', [
            'HTTPS' => 'on', 'HTTP_HOST' => 'another.example.test',
            'SERVER_NAME' => 'opgateway.test', 'REMOTE_ADDR' => '127.0.0.1',
        ])['status']);
    }

    public function testMissingDatabaseConnectionFailsClosed(): void
    {
        $entry = new AccountWebEntry([]);
        self::assertSame(503, $entry->handle('GET', '/account/login', [
            'HTTPS' => 'on', 'HTTP_HOST' => 'opgateway.test',
            'SERVER_NAME' => 'opgateway.test', 'REMOTE_ADDR' => '127.0.0.1',
        ])['status']);
    }
}
