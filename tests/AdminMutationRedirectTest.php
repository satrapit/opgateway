<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AdminMutationRedirect;
use PHPUnit\Framework\TestCase;

/** Redirects are fixed server-side paths, never caller-supplied URLs. */
final class AdminMutationRedirectTest extends TestCase
{
    public function testEveryMutationReturnsToItsPage(): void
    {
        foreach ([
            'approve_user' => '/admin/users',
            'create_client' => '/admin/owners',
            'activate_client' => '/admin/clients',
            'disable_client' => '/admin/clients',
            'request_ip' => '/admin/clients',
            'approve_ip' => '/admin/ips/pending',
            'revoke_ip' => '/admin/ips/approved',
        ] as $action => $destination) {
            self::assertSame($destination, AdminMutationRedirect::forAction($action));
        }
    }

    public function testUntrustedActionCannotSupplyRedirectUrl(): void
    {
        foreach (['', '../account', '//example.com', 'https://example.com'] as $value) {
            self::assertSame('/admin', AdminMutationRedirect::forAction($value));
        }
    }

    public function testBothActionRoutesUseOnlyFixedRedirectMapper(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Http/PrivateBrowserAdapter.php');
        self::assertIsString($source);
        self::assertSame(2, substr_count($source, "AdminMutationRedirect::forAction(\$form['action'])"));
    }
}
