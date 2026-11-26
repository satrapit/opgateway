<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Mail\PdoSmtpSettings;
use OPGateway\Mail\PhpMailerVerificationMailer;
use OPGateway\Mail\SmtpConfigurationException;
use OPGateway\Security\SealedSecret;
use OPGateway\Security\PdoSecurityEventRecorder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdoSmtpSettingsTest extends TestCase
{
    public function testSaveWritesSevenKeyValueSettingsWithEncryptedPassword(): void
    {
        $vault = new SealedSecret(str_repeat('a1', 32));
        $read = $this->createStub(PDOStatement::class);
        $read->method('fetchAll')->willReturn([]);
        $write = $this->createMock(PDOStatement::class);
        $write->expects(self::once())->method('execute')
            ->with(self::callback(static function (array $params) use ($vault): bool {
                $map = [];
                foreach (array_chunk($params, 2) as $pair) {
                    $map[$pair[0]] = $pair[1];
                }
                self::assertCount(7, $map);
                self::assertSame('smtp.example.net', $map['smtp.host']);
                self::assertSame('587', $map['smtp.port']);
                self::assertSame('starttls', $map['smtp.encryption']);
                self::assertSame('mailer@example.net', $map['smtp.username']);
                self::assertSame('from@example.net', $map['smtp.from_email']);
                self::assertSame('Gateway', $map['smtp.from_name']);
                self::assertStringStartsWith('v1:', $map['smtp.password']);
                self::assertNotSame('secret-test-password', $map['smtp.password']);
                self::assertSame(
                    'secret-test-password',
                    $vault->decrypt($map['smtp.password'], 'smtp:1'),
                );
                return true;
            }))->willReturn(true);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($read, $write): PDOStatement {
                if (str_starts_with($sql, 'SELECT setting_key')) {
                    return $read;
                }
                self::assertStringContainsString('INSERT INTO settings', $sql);
                self::assertStringNotContainsString('smtp_settings', $sql);
                return $write;
            },
        );
        $smtp = new PdoSmtpSettings($pdo, $vault);
        $smtp->save(
            'smtp.example.net', 587, 'starttls', 'mailer@example.net',
            'secret-test-password', 'from@example.net', 'Gateway',
        );
    }

    public function testGmailActionFlagDefaultsOffAndCanBeToggled(): void
    {
        $stored = false;
        $read = $this->createStub(PDOStatement::class);
        $read->method('execute')->willReturn(true);
        $read->method('fetchColumn')->willReturnCallback(
            static function () use (&$stored): string|false { return $stored; },
        );

        $write = $this->createMock(PDOStatement::class);
        $write->expects(self::exactly(2))->method('execute')
            ->willReturnCallback(static function (?array $params = null) use (&$stored): bool {
                self::assertIsArray($params);
                self::assertCount(1, $params);
                self::assertContains($params[0], ['0', '1']);
                $stored = $params[0];
                return true;
            });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($read, $write): PDOStatement {
                self::assertStringContainsString('smtp.gmail_actions_enabled', $sql);
                return str_starts_with($sql, 'SELECT') ? $read : $write;
            },
        );
        $settings = new PdoSmtpSettings($pdo, new SealedSecret(str_repeat('a1', 32)));
        self::assertFalse($settings->gmailActionsEnabled());
        $settings->saveGmailActions(true);
        self::assertTrue($settings->gmailActionsEnabled());
        $settings->saveGmailActions(false);
        self::assertFalse($settings->gmailActionsEnabled());
    }

    public function testGmailToggleAuditEventIsAccepted(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $requestId = str_repeat('a', 32);
        $stmt->expects(self::once())->method('execute')
            ->with(['gmail_verification_action_changed', $requestId])
            ->willReturn(true);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        (new PdoSecurityEventRecorder($pdo))->record(
            'gmail_verification_action_changed', $requestId,
        );
    }

    public function testIncompleteSettingsFailClosed(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn(['smtp.host' => 'smtp.example.net']);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $smtp = new PdoSmtpSettings($pdo, new SealedSecret(str_repeat('a1', 32)));
        self::assertFalse($smtp->configured());
        self::assertNull($smtp->connection());
        self::assertFalse($smtp->summary()['password_set']);
    }

    public function testMailerClassifiesIncompleteSettingsAsConfigurationFailure(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn(['smtp.host' => 'smtp.example.net']);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $mailer = new PhpMailerVerificationMailer(
            new PdoSmtpSettings($pdo, new SealedSecret(str_repeat('a1', 32))),
        );

        $this->expectException(SmtpConfigurationException::class);
        $mailer->sendGoogleIdentityConfirmation(
            'owner@example.com', str_repeat('a', 64), 'https://opgateway.test',
        );
    }
}
