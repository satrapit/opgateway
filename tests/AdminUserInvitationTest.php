<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\AdminUserInvitation;
use OPGateway\Identity\EmailVerificationService;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Identity\PdoEmailVerificationRepository;
use OPGateway\Mail\InvitationMailer;
use OPGateway\Security\AdminActionLimiter;
use OPGateway\Security\SecurityEventRecorder;
use OPGateway\View\TwigRenderer;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** The admin never gets a new user's password and never creates an admin role. */
final class AdminUserInvitationTest extends TestCase
{
    /** @return PDO */
    private static function database(): PDO
    {
        return new class extends PDO {
            /** @var list<array{sql:string,params:array}> */
            public array $calls = [];
            public bool $transaction = false;
            public bool $existing = false;
            public function __construct()
            {
            }
            public function beginTransaction(): bool
            {
                $this->transaction = true;
                return true;
            }
            public function commit(): bool
            {
                $this->transaction = false;
                return true;
            }
            public function rollBack(): bool
            {
                $this->transaction = false;
                return true;
            }
            public function inTransaction(): bool
            {
                return $this->transaction;
            }
            public function lastInsertId(?string $name = null): string|false
            {
                return '17';
            }
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this, $query) extends PDOStatement {
                    public function __construct(private PDO $db, private string $sql)
                    {
                    }
                    public function execute(?array $params = null): bool
                    {
                        $this->db->calls[] = [
                            'sql' => $this->sql, 'params' => $params ?? [],
                        ];
                        return true;
                    }
                    public function fetchColumn(int $column = 0): mixed
                    {
                        return str_contains($this->sql, 'SELECT 1 FROM users AS operator')
                            ? 1 : false;
                    }
                    public function fetch(
                        int $mode = PDO::FETCH_DEFAULT,
                        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                        int $cursorOffset = 0,
                    ): mixed {
                        return $this->db->existing && str_contains($this->sql, 'FROM users WHERE email')
                            ? ['id' => 17, 'status' => 'pending',
                                'email_verified_at' => null,
                                'signup_source' => 'admin_invite'] : false;
                    }
                    public function rowCount(): int
                    {
                        return 1;
                    }
                };
            }
        };
    }

    public function testCreatesPendingInviteWithoutPasswordDisclosureOrAdminRole(): void
    {
        $db = self::database();
        $mailer = new class implements InvitationMailer {
            public ?string $recipient = null;
            public ?string $token = null;
            public function sendInvitation(
                string $email, string $token, string $origin,
            ): void {
                $this->recipient = $email;
                $this->token = $token;
                TestCase::assertSame('https://opgateway.arsamnet.com', $origin);
            }
        };
        $limiter = $this->createStub(AdminActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $events = $this->createMock(SecurityEventRecorder::class);
        $events->expects(self::once())->method('record')
            ->with('admin_user_invited', str_repeat('a', 32));
        $service = new AdminUserInvitation(
            $db, new PasswordHasher(),
            new EmailVerificationService(new PdoEmailVerificationRepository($db)),
            $mailer, $limiter, $events,
        );
        self::assertSame('accepted', $service->invite(
            4, ' NewUser@Example.test ', '192.0.2.8',
            str_repeat('a', 32), 'https://opgateway.arsamnet.com',
        ));
        self::assertSame('newuser@example.test', $mailer->recipient);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $mailer->token);
        self::assertFalse($db->inTransaction());
        $insert = array_values(array_filter($db->calls,
            static fn (array $call): bool =>
                str_contains($call['sql'], 'INSERT INTO users'),
        ));
        self::assertCount(1, $insert);
        self::assertSame('newuser@example.test', $insert[0]['params'][0]);
        self::assertTrue(password_get_info($insert[0]['params'][1])['algo'] !== null);
        self::assertStringContainsString("'admin_invite'", $insert[0]['sql']);
        self::assertStringNotContainsString('user_roles', $insert[0]['sql']);
        self::assertStringNotContainsString('admin', $insert[0]['params'][1]);
        self::assertTrue($db->inTransaction() === false);
    }

    public function testResendsUnverifiedInviteWithoutNewUserInsert(): void
    {
        $db = self::database();
        $db->existing = true;
        $mailer = $this->createMock(InvitationMailer::class);
        $mailer->expects(self::once())->method('sendInvitation');
        $limiter = $this->createStub(AdminActionLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $service = new AdminUserInvitation($db, new PasswordHasher(),
            new EmailVerificationService(new PdoEmailVerificationRepository($db)),
            $mailer, $limiter, $this->createStub(SecurityEventRecorder::class));
        self::assertSame('accepted', $service->invite(4,
            'user@example.test', '192.0.2.8', str_repeat('b', 32),
            'https://opgateway.arsamnet.com'));
        self::assertSame([], array_filter($db->calls,
            static fn (array $call): bool =>
                str_contains($call['sql'], 'INSERT INTO users')));
    }

    public function testInviteUserViewRequiresEmailAndCsrfWithoutPasswordReentry(): void
    {
        $twig = new TwigRenderer(dirname(__DIR__) . '/templates');
        $html = $twig->render('admin/invite-user.html.twig', [
            'active_section' => 'users', 'section_title' => 'Invite user',
            'administrator_label' => 'Administrator',
            'csrf_token' => str_repeat('a', 64),
            'smtp_ready' => true,
            'invitation_sent' => false,
        ]);
        self::assertStringContainsString('action="/admin/users/invite"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringNotContainsString('name="current_password"', $html);
        self::assertStringContainsString('Forgot password', $html);
        self::assertStringNotContainsString('name="role"', $html);
    }
}
