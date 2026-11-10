<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Provider\OpenproviderRoutePolicy;
use PHPUnit\Framework\TestCase;

final class OpenproviderRoutePolicyTest extends TestCase
{
    public function testExplicitSafeRoutesWithoutBypassSwitch(): void
    {
        self::assertTrue(OpenproviderRoutePolicy::allowed('GET', '/v1/domains'));
        self::assertTrue(OpenproviderRoutePolicy::allowed('POST', '/v1/domains/check'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('POST', '/v1/domains'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('POST', '/v1/domains'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('PUT', '/v1/domains/5'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('PATCH', '/v1/domains/5'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('DELETE', '/v1/domains/5'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', '/v1/auth/login'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', '/v1/../../internal'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', '/v1/domains%2Fauth'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', 'https://example.com/'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('GET', '/v1/server-admin'));
        self::assertFalse(OpenproviderRoutePolicy::allowed('TRACE', '/v1/domains'));
    }

    public function testQueriesCannotSmuggleRemoteUrlsOrFragments(): void
    {
        self::assertTrue(OpenproviderRoutePolicy::validQuery('limit=10&offset=0'));
        self::assertFalse(OpenproviderRoutePolicy::validQuery('next=https://example.org'));
        self::assertFalse(OpenproviderRoutePolicy::validQuery('x=%2Fadmin'));
        self::assertFalse(OpenproviderRoutePolicy::validQuery('x=a#fragment'));
    }
}
