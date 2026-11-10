<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\BrowserGetPath;
use PHPUnit\Framework\TestCase;

final class BrowserGetPathTest extends TestCase
{
    public function testAccountAndAdminPageTrailingSlashesAreNormalized(): void
    {
        foreach ([
            '/account', '/account/login', '/account/register',
            '/account/forgot-password', '/account/reset-password', '/account/verify',
            '/account/provider', '/account/clients', '/account/keys', '/account/ips',
            '/admin', '/admin/login', '/admin/users', '/admin/owners', '/admin/clients',
            '/admin/ips/pending', '/admin/ips/approved', '/admin/settings/smtp',
        ] as $path) {
            self::assertSame($path, BrowserGetPath::normalize('GET', $path . '/'));
            self::assertSame($path, BrowserGetPath::normalize('GET', $path));
        }
    }

    public function testUnknownPathsAndMutatingMethodsRemainUnchanged(): void
    {
        foreach (['/', '/health/', '/v1/domains/', '/account/unknown/', '/admin/unknown/',
            '/account/clients//', '/account/keys/issue/', '/admin/logout/'] as $path) {
            self::assertSame($path, BrowserGetPath::normalize('GET', $path));
        }

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            foreach (['/account/forgot-password/', '/account/login/', '/admin/login/',
                '/admin/review/', '/account/keys/issue/'] as $path) {
                self::assertSame($path, BrowserGetPath::normalize($method, $path));
            }
        }
    }
}
