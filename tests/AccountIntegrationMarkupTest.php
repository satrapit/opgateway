<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AccountViews;
use PHPUnit\Framework\TestCase;

final class AccountIntegrationMarkupTest extends TestCase
{
    public function testOwnerDashboardNeverExposesUpstreamPasswordOrToken(): void
    {
        $data = [
            'email' => 'account@example.test',
            'clients' => [
                ['id' => 12, 'label' => 'My API', 'status' => 'disabled'],
            ],
            'credentials' => [],
            'ips' => [],
        ];
        $html = AccountViews::dashboard(str_repeat('a', 64), $data, true, true, [
            'configured' => true, 'username' => 'api@example.test',
        ]);
        $provider = AccountViews::dashboard(str_repeat('a', 64), $data, true, true, [
            'configured' => true, 'username' => 'api@example.test',
        ], 'provider');
        $clients = AccountViews::dashboard(str_repeat('a', 64), $data, true, true, [], 'clients');
        $ips = AccountViews::dashboard(str_repeat('a', 64), $data, true, true, [], 'ips');
        self::assertStringContainsString('action="/account/provider/save"', $provider);
        self::assertStringContainsString('action="/account/clients/create"', $clients);
        self::assertStringContainsString('action="/account/ips/request"', $ips);
        self::assertStringContainsString('name="current_password"', $provider);
        self::assertStringContainsString('value="api@example.test"', $provider);
        self::assertStringNotContainsString('upstream_token', $html);
        self::assertStringNotContainsString('password_cipher', $html);
        self::assertStringNotContainsString('name="password" value=', $html);
    }

    public function testLegacyDashboardDoesNotShowIntegrationFormWhenDisabled(): void
    {
        $data = [
            'email' => 'account@example.test',
            'clients' => [], 'credentials' => [], 'ips' => [],
        ];
        $html = AccountViews::dashboard(str_repeat('a', 64), $data, false);
        self::assertStringNotContainsString('/account/provider/save', $html);
        self::assertStringNotContainsString('/account/clients/create', $html);
        self::assertStringNotContainsString('/account/ips/request', $html);
    }
}
