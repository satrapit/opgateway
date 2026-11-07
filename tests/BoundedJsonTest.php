<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\BoundedJson;
use PHPUnit\Framework\TestCase;

final class BoundedJsonTest extends TestCase
{
    public function testAcceptsSmallObjectOnly(): void
    {
        self::assertSame(['action' => 'approve_user', 'user_id' => 2],
            BoundedJson::decode('{"action":"approve_user","user_id":2}'));
        self::assertNull(BoundedJson::decode('[{"action":"approve_user"}]'));
        self::assertNull(BoundedJson::decode('null'));
        self::assertNull(BoundedJson::decode(''));
        self::assertNull(BoundedJson::decode('{invalid'));
        self::assertNull(BoundedJson::decode(str_repeat('a', 16385)));
    }

    public function testRejectsUnknownFieldsAndCoercedIds(): void
    {
        self::assertTrue(BoundedJson::fields(['action' => 'approve_user', 'user_id' => 7],
            ['action', 'user_id']));
        self::assertFalse(BoundedJson::fields(['action' => 'approve_user', 'user_id' => 7, 'admin_id' => 9],
            ['action', 'user_id']));
        self::assertNull(BoundedJson::positiveId('7'));
        self::assertNull(BoundedJson::positiveId(0));
        self::assertSame(7, BoundedJson::positiveId(7));
    }
}
