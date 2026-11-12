<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Http\GmailVerificationAction;
use OPGateway\Identity\EmailVerificationRepository;
use OPGateway\Identity\EmailVerificationService;
use OPGateway\Identity\EmailVerificationToken;
use OPGateway\Mail\GmailVerificationMarkup;
use PHPUnit\Framework\TestCase;

final class GmailVerificationActionTest extends TestCase
{
    private const GMAIL_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/1.0 (KHTML, like Gecko; Gmail Actions)';

    public function testMarkupDeclaresPostConfirmActionToExactOrigin(): void
    {
        $token = str_repeat('a', 64);
        $markup = GmailVerificationMarkup::render('https://opgateway.arsamnet.com', $token);
        self::assertStringContainsString('application/ld+json', $markup);
        $json = substr($markup, strpos($markup, '>') + 1, -strlen('</script>'));
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('EmailMessage', $data['@type']);
        self::assertSame('ConfirmAction', $data['potentialAction']['@type']);
        self::assertSame('Verify Email', $data['potentialAction']['name']);
        self::assertSame('HttpActionHandler', $data['potentialAction']['handler']['@type']);
        self::assertSame(
            'https://opgateway.arsamnet.com/account/verify/gmail?token=' . $token,
            $data['potentialAction']['handler']['url'],
        );
        self::assertStringNotContainsString('<script>alert', $markup);
    }

    public function testMarkupRejectsNonHttpsAndInvalidTokens(): void
    {
        foreach (['http://example.test', 'https://example.test/path'] as $origin) {
            try {
                GmailVerificationMarkup::render($origin, str_repeat('a', 64));
                self::fail('Invalid origin was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        GmailVerificationMarkup::render('https://example.test', 'not-a-token');
    }

    public function testOneClickPostConsumesOnlyOnceAndDoesNotRequireBrowserCsrf(): void
    {
        $repo = new class implements EmailVerificationRepository {
            public string $hash = '';
            public bool $used = false;

            public function issue(int $userId, string $tokenHash, int $ttlSeconds): void
            {
                $this->hash = $tokenHash;
            }

            public function consume(string $tokenHash): bool
            {
                if ($this->used || !hash_equals($this->hash, $tokenHash)) {
                    return false;
                }
                $this->used = true;
                return true;
            }
        };
        $service = new EmailVerificationService($repo);
        $token = $service->issueForUser(42);
        $action = new GmailVerificationAction($service, true);
        $query = 'token=' . $token;
        $type = 'application/x-www-form-urlencoded';
        $payload = 'confirmed=Approved';

        self::assertSame(404, $action->handle('GET', $type, $payload, $query, self::GMAIL_UA)['status']);
        self::assertSame(400, $action->handle('POST', $type, $payload, $query, 'curl/8.0')['status']);
        self::assertSame(400, $action->handle('POST', 'application/json', $payload, $query, self::GMAIL_UA)['status']);
        self::assertSame(400, $action->handle('POST', $type, 'confirmed=No', $query, self::GMAIL_UA)['status']);
        self::assertSame(400, $action->handle('POST', $type, $payload, 'token=bad', self::GMAIL_UA)['status']);
        self::assertFalse($repo->used);
        self::assertSame(200, $action->handle('POST', $type, $payload, $query, self::GMAIL_UA)['status']);
        self::assertTrue($repo->used);
        self::assertSame(400, $action->handle('POST', $type, $payload, $query, self::GMAIL_UA)['status']);
    }

    public function testFeatureFlagDisablesActionEvenForValidRequests(): void
    {
        $action = new GmailVerificationAction(
            new EmailVerificationService(new class implements EmailVerificationRepository {
                public function issue(int $userId, string $tokenHash, int $ttlSeconds): void {}
                public function consume(string $tokenHash): bool { return true; }
            }),
            false,
        );
        self::assertSame(404, $action->handle(
            'POST', 'application/x-www-form-urlencoded', 'confirmed=Approved',
            'token=' . str_repeat('a', 64), self::GMAIL_UA,
        )['status']);
    }

    public function testSmtpAdminExposesOptInWithoutPasswordFormReuse(): void
    {
        $root = dirname(__DIR__);
        $template = file_get_contents($root . '/templates/admin/smtp.html.twig');
        self::assertIsString($template);
        self::assertStringContainsString('action="/admin/settings/smtp/gmail-actions"', $template);
        self::assertStringContainsString('name="enabled"', $template);
        self::assertStringContainsString('Disabled (default)', $template);
        $source = file_get_contents($root . '/src/Mail/PhpMailerVerificationMailer.php');
        self::assertIsString($source);
        self::assertStringContainsString('GmailVerificationMarkup::render', $source);
        self::assertStringContainsString('$mailer->AltBody = $message;', $source);
    }
}
