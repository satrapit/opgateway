<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\CreatePasswordResetTokens;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Http\AccountViews;
use OPGateway\Http\SelfServiceBrowserController;
use PHPUnit\Framework\TestCase;

final class PasswordResetPolicyTest extends TestCase
{
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testRecoveryPagesRequirePostCsrfAndPasswordConfirmation(): void
    {
        $forgot = AccountViews::forgotPassword(self::CSRF);
        self::assertStringContainsString('action="/account/forgot-password"', $forgot);
        self::assertStringContainsString('name="_csrf"', $forgot);
        $reset = AccountViews::resetPasswordForm(self::CSRF, str_repeat('a', 64));
        self::assertStringContainsString('action="/account/reset-password"', $reset);
        self::assertStringContainsString('name="password_confirmation"', $reset);
        self::assertTrue(SelfServiceBrowserController::route('/account/forgot-password'));
        self::assertTrue(SelfServiceBrowserController::route('/account/reset-password'));
    }

    public function testMalformedTokenDoesNotShowPasswordForm(): void
    {
        $page = AccountViews::resetPasswordForm(self::CSRF, '<script>');
        self::assertStringNotContainsString('action="/account/reset-password"', $page);
        self::assertStringContainsString('invalid or expired', $page);
    }

    public function testManualUpgradeMatchesHashedTokenMigration(): void
    {
        $migration = new CreatePasswordResetTokens();
        $script = file_get_contents(dirname(__DIR__) . '/database/sql/upgrade_202611100003.sql');
        self::assertIsString($script);
        self::assertStringContainsString(MigrationRunner::checksum($migration), $script);
        foreach ($migration->statements() as $statement) {
            self::assertStringContainsString($statement . ';', $script);
        }
        self::assertStringContainsString('token_hash CHAR(64)', $script);
        self::assertStringContainsString('consumed_at DATETIME(6)', $script);
    }
}
