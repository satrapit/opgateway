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

    public function testOverviewIsOnlySummaryNotOneLongQueuePage(): void
    {
        $pages = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $queues = [
            'users' => [['id' => 4, 'email' => '<script>alert(1)</script>']],
            'owners' => [], 'clients' => [], 'ips' => [], 'approved_ips' => [],
        ];
        $html = $pages->dashboard(self::CSRF, 'Administrator', $queues, true);
        self::assertStringContainsString('Access management', $html);
        self::assertStringContainsString('Shown (max. 50)', $html);
        self::assertStringContainsString('href="/admin/users"', $html);
        self::assertStringNotContainsString('Pending user approvals', $html);
        self::assertStringNotContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('action="/admin/review"', $html);
        self::assertStringNotContainsString('action="/admin/provision"', $html);
    }

    public function testDedicatedClientPagePreservesExactActionsAndEscaping(): void
    {
        $pages = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $queues = [
            'users' => [],
            'owners' => [],
            'clients' => [['id' => 15, 'label' => '<client>', 'status' => 'active',
                'owner_email' => 'owner@example.test']],
            'ips' => [], 'approved_ips' => [],
        ];
        $html = $pages->section('clients', self::CSRF, 'Administrator', $queues, true);
        self::assertStringContainsString('&lt;client&gt;', $html);
        self::assertStringNotContainsString('<client>', $html);
        self::assertStringContainsString('name="action" value="disable_client"', $html);
        self::assertStringContainsString('name="action" value="request_ip"', $html);
        self::assertStringContainsString('name="target_id" value="15"', $html);
        self::assertStringNotContainsString('secret_hash', $html);
        self::assertStringNotContainsString('Pending user approvals', $html);
        self::assertStringContainsString('aria-current="page"', $html);

        $readOnly = $pages->section('clients', self::CSRF, 'Administrator', $queues, false);
        self::assertStringNotContainsString('action="/admin/review"', $readOnly);
        self::assertStringNotContainsString('action="/admin/provision"', $readOnly);
    }

    public function testLocalQaNavigationRequiresExplicitPresentationFlag(): void
    {
        $renderer = new TwigRenderer(dirname(__DIR__) . '/templates');
        $hidden = (new TwigAdminPageRenderer($renderer))->dashboard(self::CSRF, 'Administrator');
        self::assertStringNotContainsString('href="/admin/qa"', $hidden);

        $visible = (new TwigAdminPageRenderer($renderer, true))
            ->dashboard(self::CSRF, 'Administrator');
        self::assertStringContainsString('href="/admin/qa"', $visible);
        self::assertStringNotContainsString('aria-current="page" href="/admin/qa"', $visible);
    }

    public function testUnrecognizedSectionCannotSelectTemplate(): void
    {
        $pages = new TwigAdminPageRenderer(new TwigRenderer(dirname(__DIR__) . '/templates'));
        $this->expectException(InvalidArgumentException::class);
        $pages->section('../.env', self::CSRF, 'Administrator');
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
