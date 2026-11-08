<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Database\PdoConnectionFactory;
use PHPUnit\Framework\TestCase;

final class PdoConnectionFactoryTest extends TestCase
{
    public function testInvalidPortIsRejectedBeforeOpeningConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PdoConnectionFactory::open([
            'DB_HOST' => 'db', 'DB_PORT' => 'invalid',
            'DB_NAME' => 'example', 'DB_USER' => 'example',
            'DB_PASSWORD' => 'placeholder',
        ]);
    }

    public function testDsnInjectionIsRejectedBeforeOpeningConnection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PdoConnectionFactory::open([
            'DB_HOST' => 'db;dbname=other', 'DB_PORT' => '3306',
            'DB_NAME' => 'example', 'DB_USER' => 'example',
            'DB_PASSWORD' => 'placeholder',
        ]);
    }
}
