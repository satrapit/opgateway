<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\PdoSecurityEventRecorder;
use PHPUnit\Framework\TestCase;

final class GatewaySecurityEventPolicyTest extends TestCase
{
    public function testOnlyMetadataEventCodesAreAllowlisted(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Security/PdoSecurityEventRecorder.php');
        self::assertIsString($source);
        foreach ([
            'smtp_settings_changed',
            'provider_credentials_saved',
            'owner_client_requested',
            'owner_ip_requested',
            'owner_management_denied',
            'owner_management_throttled',
            'gateway_request_admitted',
        ] as $event) {
            self::assertStringContainsString("'$event'", $source);
        }
        self::assertStringNotContainsString('password_hash', $source);
        self::assertStringNotContainsString('raw_body', $source);
    }
}
