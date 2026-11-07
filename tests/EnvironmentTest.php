<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Config\Environment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentTest extends TestCase
{
    public function testRejectsMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        Environment::load('/nonexistent/opgateway/test-env-file');
    }
}
