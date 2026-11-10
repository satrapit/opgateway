<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Mail\PdoSmtpSettings;
use OPGateway\Security\SealedSecret;
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
}
