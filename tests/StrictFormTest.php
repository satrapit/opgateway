<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\StrictForm;
use PHPUnit\Framework\TestCase;

final class StrictFormTest extends TestCase
{
    public function testDecodesExactLoginFields(): void
    {
        self::assertSame(
            ['_csrf' => 'abc', 'email' => 'a@example.org', 'password' => 'hello+world'],
            StrictForm::decode('_csrf=abc&email=a%40example.org&password=hello%2Bworld', ['_csrf', 'email', 'password']),
        );
    }

    public function testRejectsDuplicatesAndArrayInjection(): void
    {
        self::assertNull(StrictForm::decode('email=x&email=y', ['email']));
        self::assertNull(StrictForm::decode('email%5B%5D=x', ['email']));
        self::assertNull(StrictForm::decode('email=x&role=admin', ['email']));
    }

    public function testRejectsMalformedAndMissingValues(): void
    {
        self::assertNull(StrictForm::decode('email=%XX', ['email']));
        self::assertNull(StrictForm::decode('email', ['email']));
        self::assertNull(StrictForm::decode('', ['email']));
        self::assertNull(StrictForm::decode('email=x', ['email', 'password']));
    }
}
