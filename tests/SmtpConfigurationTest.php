<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Mail\SmtpConfiguration;
use PHPUnit\Framework\TestCase;

final class SmtpConfigurationTest extends TestCase
{
    public function testOnlyAuthenticatedTlsSmtpConfigsAreAccepted(): void
    {
        $c = new SmtpConfiguration(
            'smtp.example.net', 587, 'starttls', 'account@example.net', 'secret-pass',
            'sender@example.net', 'OPGateway',
        );
        self::assertSame('smtp.example.net', $c->host);
    }

    public function testRejectsPlaintextOrUntrustedSender(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmtpConfiguration(
            'smtp.example.net', 25, 'plain', 'mailer', 'secret',
            'sender@example.net', 'OPGateway',
        );
    }

    public function testRejectsLocalhostAndIpLiterals(): void
    {
        foreach (['localhost', '127.0.0.1', 'smtp.local', '192.0.2.4'] as $hostname) {
            try {
                new SmtpConfiguration(
                    $hostname, 465, 'smtps', 'mailer', 'secret',
                    'sender@example.net', 'OPGateway',
                );
                self::fail('Unsafe SMTP hostname accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
