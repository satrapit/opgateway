<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Http\AccountViews;
use OPGateway\Security\ApiKey;
use PHPUnit\Framework\TestCase;

final class AccountViewsTest extends TestCase
{
    private const TOKEN = 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899';

    private function overview(): array
    {
        return [
            'email' => '<owner@example.com>',
            'clients' => [
                ['id' => 13, 'label' => '<img src=x onerror=alert(1)>', 'status' => 'active'],
                ['id' => 14, 'label' => 'Disabled client', 'status' => 'disabled'],
            ],
            'credentials' => [
                ['id' => 9, 'client_id' => 13, 'prefix' => 'opg_0123456789abcdef', 'revoked' => false],
                ['id' => 10, 'client_id' => 14, 'prefix' => 'opg_abcdef0123456789', 'revoked' => true],
            ],
            'ips' => [
                ['id' => 7, 'client_id' => 13, 'address' => '2001:db8::25', 'enabled' => true],
            ],
        ];
    }

    public function testOnlyOwnerMetadataIsRenderedWhenKeysAreDisabled(): void
    {
        $html = AccountViews::dashboard(self::TOKEN, $this->overview(), false);
        self::assertStringContainsString('&lt;owner@example.com&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('opg_0123456789abcdef', $html);
        self::assertStringContainsString('2001:db8::25', $html);
        self::assertStringNotContainsString('action="/account/keys/issue"', $html);
        self::assertStringNotContainsString('action="/account/keys/revoke"', $html);
    }

    public function testOnlyActiveClientsCanIssueAndOnlyNonRevokedKeysCanBeRevoked(): void
    {
        $html = AccountViews::dashboard(self::TOKEN, $this->overview(), true);
        self::assertSame(1, substr_count($html, 'action="/account/keys/issue"'));
        self::assertSame(1, substr_count($html, 'action="/account/keys/revoke"'));
        self::assertStringContainsString('name="password"', $html);
        self::assertStringContainsString('name="client_id" value="13"', $html);
        self::assertStringContainsString('name="credential_id" value="9"', $html);
        self::assertStringNotContainsString('name="credential_id" value="10"', $html);
    }

    public function testIssuedBearerAppearsOnlyInOneTimePageNotUrl(): void
    {
        $key = ApiKey::issue()['key'];
        $html = AccountViews::issued($key);
        self::assertStringContainsString($key, $html);
        self::assertStringContainsString('never be shown again', $html);
        self::assertStringContainsString('<meta name="referrer" content="no-referrer">', $html);
        self::assertStringContainsString('href="/account"', $html);
        self::assertStringNotContainsString('?key=', $html);
    }

    public function testMalformedSecretsAndCsrfAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountViews::issued('<script>');
    }

    public function testLoginFormUsesCsrfAndGenericFailure(): void
    {
        $html = AccountViews::login(self::TOKEN, true);
        self::assertStringContainsString('action="/account/login"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('Sign-in was not successful.', $html);
    }
}
