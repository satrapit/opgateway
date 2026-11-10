<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AdminMutationRedirect;
use PHPUnit\Framework\TestCase;

/** PRG targets must be constant, allowlisted, and independent of request headers. */
final class AdminMutationRedirectTest extends TestCase
{
    public function testEveryAllowedMutationReturnsToItsOwningTwigPage(): void
    {
        $expected = [
            'approve_user' => '/admin/users',
            'create_client' => '/admin/owners',
            'activate_client' => '/admin/clients',
            'disable_client' => '/admin/clients',
            'request_ip' => '/admin/clients',
            'approve_ip' => '/admin/ips/pending',
            'revoke_ip' => '/admin/ips/approved',
        ];
        foreach ($expected as $action => $destination) {
            self::assertSame($destination, AdminMutationRedirect::forAction($action, true), $action);
            self::assertSame('/admin', AdminMutationRedirect::forAction($action, false), $action);
        }
    }

    public function testUnknownOrUntrustedActionCanNeverSupplyRedirectUrl(): void
    {
        foreach ([
            '', '/evil', '../account', 'https://example.com',
            '//example.com', "disable_client\nLocation: /other",
        ] as $value) {
            self::assertSame('/admin', AdminMutationRedirect::forAction($value, true));
        }
    }

    public function testBothWriteRoutesUseOnlyFixedRedirectMapper(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Http/PrivateBrowserAdapter.php');
        self::assertIsString($source);
        self::assertSame(2, substr_count($source,
            "AdminMutationRedirect::forAction(\$form['action'], \$this->pages !== null)"));
    }
}
