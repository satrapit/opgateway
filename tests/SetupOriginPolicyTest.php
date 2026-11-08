<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\SetupOriginPolicy;
use PHPUnit\Framework\TestCase;

final class SetupOriginPolicyTest extends TestCase
{
    private const ORIGIN = 'https://opgateway.example.org';

    public function testAcceptsExplicitExactOrigin(): void
    {
        self::assertNull(SetupOriginPolicy::denialEvent(self::ORIGIN, [
            'HTTP_ORIGIN' => self::ORIGIN,
        ]));
    }

    public function testRejectsExplicitMismatchEvenWithSameOriginMetadata(): void
    {
        self::assertSame('setup.origin_mismatch', SetupOriginPolicy::denialEvent(self::ORIGIN, [
            'HTTP_ORIGIN' => 'https://other.example.org',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_SEC_FETCH_MODE' => 'navigate',
        ]));
        self::assertSame('setup.origin_mismatch', SetupOriginPolicy::denialEvent(self::ORIGIN, [
            'HTTP_ORIGIN' => 'null',
        ]));
    }

    public function testPermitsMissingOriginOnlyForSameOriginNavigation(): void
    {
        $metadata = [
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_SEC_FETCH_MODE' => 'navigate',
        ];
        self::assertNull(SetupOriginPolicy::denialEvent(self::ORIGIN, $metadata));
        self::assertNull(SetupOriginPolicy::denialEvent(self::ORIGIN, [
            'HTTP_ORIGIN' => '',
            ...$metadata,
        ]));
    }

    public function testMissingOriginFailsClosedWithoutFetchMetadata(): void
    {
        self::assertSame('setup.origin_missing',
            SetupOriginPolicy::denialEvent(self::ORIGIN, []));
    }

    public function testMissingOriginRejectsCrossSiteAndNonNavigationRequests(): void
    {
        foreach ([
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate'],
            ['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_SEC_FETCH_MODE' => 'navigate'],
            ['HTTP_SEC_FETCH_SITE' => 'none', 'HTTP_SEC_FETCH_MODE' => 'navigate'],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors'],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        ] as $metadata) {
            self::assertSame('setup.origin_missing',
                SetupOriginPolicy::denialEvent(self::ORIGIN, $metadata));
        }
    }
}
