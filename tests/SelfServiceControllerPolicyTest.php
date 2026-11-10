<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AccountViews;
use OPGateway\Http\SelfServiceBrowserController;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SelfServiceControllerPolicyTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOptionalDependenciesFailClosedWhenNotConfigured(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $accounts = $this->createStub(LoginAccountRepository::class);
        $controller = new SelfServiceBrowserController(
            $session, $accounts, null, null, 'https://opgateway.test',
        );
        foreach ([
            '/account/register', '/account/verify', '/account/verify/resend',
            '/account/provider/save', '/account/clients/create', '/account/ips/request',
        ] as $path) {
            self::assertTrue(SelfServiceBrowserController::route($path));
            self::assertSame(404, $controller->dispatch(
                'GET', $path, null, null, '192.0.2.10', str_repeat('f', 32), '',
            )['status'], $path);
        }
        self::assertFalse(SelfServiceBrowserController::route('/v1/domains'));
        self::assertFalse(SelfServiceBrowserController::route('/admin'));
        self::assertTrue(session_destroy());
    }

    public function testPublicRegistrationMarkupHasCsrfAndNoSecrets(): void
    {
        $token = str_repeat('a', 64);
        $html = AccountViews::register($token);
        self::assertStringContainsString('action="/account/register"', $html);
        self::assertStringContainsString('action="/account/verify/resend"', $html);
        self::assertStringContainsString('name="_csrf" value="' . $token . '"', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringNotContainsString('password_hash', $html);
    }

    public function testEmailLinkIsNotAutomaticallyConsumedOnGet(): void
    {
        $html = AccountViews::verifyForm(str_repeat('a', 64), str_repeat('b', 64));
        self::assertStringContainsString('action="/account/verify"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('Confirm email', $html);
        self::assertStringNotContainsString('https://cdn.', $html);
    }
}
