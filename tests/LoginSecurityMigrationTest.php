<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\CreateLoginSecurityTables;
use OPGateway\Database\Migrations\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class LoginSecurityMigrationTest extends TestCase
{
    public function testChecksumIsIdenticalToReviewedPhpMyAdminFile(): void
    {
        $migration = new CreateLoginSecurityTables();
        self::assertSame('202611070003_create_login_security_tables', $migration->version());
        self::assertCount(2, $migration->statements());
        self::assertSame('a2932ddac794ac00993a4077dd67d7ca4dd94ec9df971d81cf15b4518342d070', MigrationRunner::checksum($migration));
    }

    public function testSecurityTablesStoreNoRawCredentialFields(): void
    {
        $migration = new CreateLoginSecurityTables();
        $sql = implode("\n", $migration->statements());
        self::assertStringContainsString('BINARY(32)', $sql);
        self::assertStringContainsString('security_events', $sql);
        foreach (['password', 'bearer_token', 'request_body', 'email_address', 'raw_ip'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($sql));
        }
    }
}
