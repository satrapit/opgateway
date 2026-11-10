<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AdministrativeWebEntry;
use PHPUnit\Framework\TestCase;

final class AdministrativeWebEntryTest extends TestCase
{
    public function testAdminRequiresHttpsAndCanonicalServerName(): void
    {
        $entry = new AdministrativeWebEntry([]);
        self::assertSame(404, $entry->handle('GET', '/admin/login', [])['status']);
        self::assertSame(404, $entry->handle('GET', '/admin', [
            'HTTPS' => 'off', 'SERVER_NAME' => 'opgateway.test',
            'HTTP_HOST' => 'opgateway.test',
        ])['status']);
        self::assertSame(404, $entry->handle('GET', '/admin/login', [
            'HTTPS' => 'on', 'SERVER_NAME' => 'opgateway.test',
            'HTTP_HOST' => 'evil.example.test',
        ])['status']);
    }

    public function testMissingDatabaseConfigurationFailsClosed(): void
    {
        $entry = new AdministrativeWebEntry([]);
        self::assertSame(503, $entry->handle('GET', '/admin/login', [
            'HTTPS' => 'on', 'SERVER_NAME' => 'opgateway.test',
            'HTTP_HOST' => 'opgateway.test', 'REMOTE_ADDR' => '127.0.0.1',
        ])['status']);
    }
}
