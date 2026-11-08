<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard: a no-referrer policy makes ordinary HTML form
 * navigation POST requests carry Origin: null in supported browsers,
 * defeating otherwise valid origin checks on setup and admin forms.
 */
final class HttpReferrerPolicyTest extends TestCase
{
    public function testFrontControllerPreservesSameOriginFormPost(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/public/index.php');
        self::assertIsString($source);
        self::assertStringContainsString("header('Referrer-Policy: same-origin');", $source);
        self::assertStringNotContainsString("header('Referrer-Policy: no-referrer');", $source);
    }

    public function testApacheReferencePolicyMatchesFrontController(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/public/.htaccess');
        self::assertIsString($source);
        self::assertStringContainsString('Header always set Referrer-Policy "same-origin"', $source);
        self::assertStringNotContainsString('Header always set Referrer-Policy "no-referrer"', $source);
    }
}
