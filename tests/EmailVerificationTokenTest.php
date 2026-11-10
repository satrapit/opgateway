<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Database\Migrations\CreateEmailVerificationTokens;
use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Identity\EmailVerificationToken;
use PHPUnit\Framework\TestCase;

final class EmailVerificationTokenTest extends TestCase
{
    public function testUniqueRandomTokensAndStableHashes(): void
    {
        $first = EmailVerificationToken::generate();
        $second = EmailVerificationToken::generate();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, $second);
        self::assertSame(hash('sha256', $first), EmailVerificationToken::hash($first));
        self::assertSame(1800, EmailVerificationToken::ttlSeconds());
    }

    public function testRejectsInvalidTokenShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailVerificationToken::hash('short');
    }

    public function testMigrationChecksumMatchesPhpMyAdminBundle(): void
    {
        $migration = new CreateEmailVerificationTokens();
        // The verification migration is immutable; later additive
        // migrations must not break this historical checksum assertion.
        self::assertContains(
            $migration->version(),
            array_map(static fn ($item): string => $item->version(), MigrationCatalog::all()),
        );
        self::assertSame('202611070002_create_email_verification_tokens', $migration->version());
        self::assertSame('0bc2e4471188fb379f737af925ebce4dcfc00537ef7b7992b17d2153b57e7727', MigrationRunner::checksum($migration));
    }
}
