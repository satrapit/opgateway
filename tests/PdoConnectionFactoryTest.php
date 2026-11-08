<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\PdoConnectionFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoConnectionFactoryTest extends TestCase
{
    public function testInvalidPortIsRejectedBeforeOpeningConnection(): void
    {
        $this->expectException(RuntimeException::class);
        PdoConnectionFactory::open([
            'DB_HOST' => 'localhost', 'DB_PORT' => 'invalid',
            'DB_NAME' => 'example', 'DB_USER' => 'example',
            'DB_PASSWORD' => 'placeholder',
        ]);
    }

    public function testInvalidSocketIsRejectedBeforeOpeningConnection(): void
    {
        $this->expectException(RuntimeException::class);
        PdoConnectionFactory::open([
            'DB_HOST' => 'localhost', 'DB_PORT' => '3306',
            'DB_NAME' => 'example', 'DB_USER' => 'example',
            'DB_PASSWORD' => 'placeholder',
            'DB_UNIX_SOCKET' => '../relative.sock',
        ]);
    }
}
