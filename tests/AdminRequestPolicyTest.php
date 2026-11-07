<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Http\AdminRequestPolicy;
use PHPUnit\Framework\TestCase;

final class AdminRequestPolicyTest extends TestCase
{
    private function policy(): AdminRequestPolicy
    {
        return new AdminRequestPolicy('https://opgateway.example.com');
    }

    private function check(
        string $method = 'POST',
        bool $https = true,
        bool $authenticated = true,
        bool $isAdmin = true,
        ?string $origin = 'https://opgateway.example.com',
        ?string $contentType = 'application/json',
        ?int $length = 100,
        bool $csrf = true,
    ): ?int {
        return $this->policy()->check(
            $method, $https, $authenticated, $isAdmin, $origin, $contentType, $length, $csrf,
        );
    }

    public function testAllowsOnlyAnAuthenticatedVerifiedAdminPost(): void
    {
        self::assertNull($this->check());
        self::assertNull($this->check(contentType: 'application/json; charset=UTF-8'));
    }

    public function testRejectsInsecureAndUnauthenticatedRequests(): void
    {
        self::assertSame(403, $this->check(https: false));
        self::assertSame(401, $this->check(authenticated: false));
        self::assertSame(403, $this->check(isAdmin: false));
    }

    public function testRejectsCrossOriginAndMissingCsrf(): void
    {
        self::assertSame(403, $this->check(origin: null));
        self::assertSame(403, $this->check(origin: 'https://evil.example'));
        self::assertSame(403, $this->check(origin: 'https://opgateway.example.com.evil.example'));
        self::assertSame(403, $this->check(csrf: false));
    }

    public function testRejectsMethodAndUnsupportedContent(): void
    {
        self::assertSame(405, $this->check(method: 'GET'));
        self::assertSame(415, $this->check(contentType: 'text/plain'));
        self::assertSame(415, $this->check(contentType: null));
    }

    public function testRejectsMissingInvalidOrOversizeBodyLength(): void
    {
        self::assertSame(413, $this->check(length: null));
        self::assertSame(413, $this->check(length: -1));
        self::assertSame(413, $this->check(length: 16385));
        self::assertNull($this->check(length: 16384));
    }

    public function testInvalidCanonicalOriginCannotInitializePolicy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AdminRequestPolicy('http://opgateway.example.com');
    }
}
