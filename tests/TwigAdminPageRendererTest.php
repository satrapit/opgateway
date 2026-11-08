<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\View\TwigAdminPageRenderer;
use OPGateway\View\TwigRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Twig\Environment as TwigEnvironment;

/**
 * Tests both fail-closed dependency bootstrap and real Twig escaping when
 * the locked composer dependencies have been installed locally.
 */
final class TwigAdminPageRendererTest extends TestCase
{
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testTwigBootstrapFailsClosedWhenDependencyMissing(): void
    {
        if (class_exists(TwigEnvironment::class)) {
            $renderer = new TwigRenderer(dirname(__DIR__) . '/templates');
            self::assertInstanceOf(TwigRenderer::class, $renderer);
            return;
        }

        $this->expectException(RuntimeException::class);
        new TwigRenderer(dirname(__DIR__) . '/templates');
    }

    public function testTemplatePathsAreAllowlistedWhenTwigIsInstalled(): void
    {
        if (!class_exists(TwigEnvironment::class)) {
            $this->expectException(RuntimeException::class);
            new TwigRenderer(dirname(__DIR__) . '/templates');
            return;
        }
        $renderer = new TwigRenderer(dirname(__DIR__) . '/templates');
        $this->expectException(RuntimeException::class);
        $renderer->render('../.env', []);
    }

    public function testTwigLoginEscapesAndKeepsExactCsrfField(): void
    {
        if (!class_exists(TwigEnvironment::class)) {
            $this->expectException(RuntimeException::class);
            new TwigRenderer(dirname(__DIR__) . '/templates');
            return;
        }

        $pages = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $html = $pages->login(self::CSRF, true);
        self::assertStringContainsString('action="/admin/login"', $html);
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $html);
        self::assertStringContainsString('Sign-in was not successful.', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testDashboardUsesOnlyBoundedRealRowsAndEscapesOutput(): void
    {
        if (!class_exists(TwigEnvironment::class)) {
            $this->expectException(RuntimeException::class);
            new TwigRenderer(dirname(__DIR__) . '/templates');
            return;
        }
        $pages = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $queues = [
            'users' => [['id' => 4, 'email' => '<script>alert(1)</script>']],
            'owners' => [['id' => 9, 'email' => 'owner@example.test']],
            'clients' => [['id' => 15, 'label' => '<client>', 'status' => 'active',
                'owner_email' => 'owner@example.test']],
            'ips' => [['id' => 19, 'client_label' => '<client>', 'address' => '192.0.2.15']],
            'approved_ips' => [],
        ];
        $html = $pages->dashboard(self::CSRF, 'Administrator', $queues, true);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('name="action" value="approve_user"', $html);
        self::assertStringContainsString('name="action" value="disable_client"', $html);
        self::assertStringContainsString('name="action" value="request_ip"', $html);
        self::assertStringContainsString('name="target_id" value="15"', $html);
        self::assertStringContainsString('Shown (max. 50)', $html);
        self::assertStringNotContainsString('secret_hash', $html);

        $readOnly = $pages->dashboard(self::CSRF, 'Administrator', $queues, false);
        self::assertStringNotContainsString('action="/admin/review"', $readOnly);
        self::assertStringNotContainsString('action="/admin/provision"', $readOnly);
    }

    public function testMalformedCsrfAlwaysFailsEvenIfDependencyUnavailable(): void
    {
        if (!class_exists(TwigEnvironment::class)) {
            $this->expectException(RuntimeException::class);
            new TwigRenderer(dirname(__DIR__) . '/templates');
            return;
        }

        $pages = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $this->expectException(InvalidArgumentException::class);
        $pages->login('<invalid>');
    }
}
