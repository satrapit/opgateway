<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Provider\OpenproviderRoutePolicy;
use PHPUnit\Framework\TestCase;

final class OpenproviderRoutePolicyTest extends TestCase
{
    public function testExplicitSafeRoutesAndWriteGate(): void
    {
        self::assertTrue(OpenproviderRoutePolicy::allowed('GET', '/v1/domains', false));
        self::assertTrue(OpenproviderRoutePolicy::allowed('POST', '/v1/domains/check', false));
        self::assertFalse(OpenproviderRoutePolicy::allowed('POST', '/v1/domains', false));
        self::assertTrue(OpenproviderRoutePolicy::allowed('POST', '/v1/domains', true));
        self::assertFalse(OpenproviderRoutePolicy::allowed('DELETE', '/v1/domains/5', false));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', '/v1/auth/login', true));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', '/v1/../../internal', true));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', '/v1/domains%2Fauth', true));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', 'https://example.com/', true));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', '/v1/server-admin', true));
        self::assertFalse(OpenproviderRoutePolicy::allowed('TRACE', '/v1/domains', true));
    }

    public function testQueriesCannotSmuggleRemoteUrlsOrFragments(): void
    {
        self::assertTrue(OpenproviderRoutePolicy::validQuery('limit=10&offset=0'));
        self::assertFalse(OpenproviderRoutePolicy::validQuery('next=https://example.org'));
        self::assertFalse(OpenproviderRoutePolicy::validQuery('x=%2Fadmin'));
        self::assertFalse(OpenproviderRoutePolicy::validQuery('x=a#fragment'));
    }
}
