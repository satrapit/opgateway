<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testChecksumStableAndSha256(): void
    {
        $migration = MigrationCatalog::all()[0];
        self::assertSame(MigrationRunner::checksum($migration), MigrationRunner::checksum($migration));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', MigrationRunner::checksum($migration));
    }
}
