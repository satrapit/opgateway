<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Form/navigation pages retain same-origin; the Google OIDC callback
 * suppresses referrer propagation of the temporary authorization code.
 * The PHP front controller and reference Apache policy have separate
 * responsibilities and must not switch all forms to no-referrer.
 */
final class HttpReferrerPolicyTest extends TestCase
{
    public function testFrontControllerPreservesSameOriginFormsAndProtectsGoogleCallback(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/public/index.php');
        self::assertIsString($source);

        // The callback alone must use no-referrer to avoid leaking the
        // authorization code in subsequent requests.
        self::assertStringContainsString(
            "header('Referrer-Policy: ' . (\$path === '/account/google/callback'",
            $source,
        );
        self::assertStringContainsString("? 'no-referrer' : 'same-origin'", $source);

        // A blanket no-referrer policy would break existing same-origin
        // form workflows; the default stays same-origin.
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
