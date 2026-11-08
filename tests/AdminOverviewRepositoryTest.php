<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\AdminOverviewRepository;
use OPGateway\Http\BrowserAuthController;
use OPGateway\Http\PrivateBrowserAdapter;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Read-only review queries and guard ordering are tested without a live DB.
 */
final class AdminOverviewRepositoryTest extends TestCase
{
    public function testPendingQueuesReadBoundedRowsAndConvertIpAddresses(): void
    {
        $data = [
            [['id' => 2, 'email' => 'review@example.com']],
            [['id' => 3, 'label' => 'Client A', 'owner_email' => 'owner@example.com']],
            [['id' => 4, 'client_label' => 'Client A',
                'ip_address' => inet_pton('2001:db8::25')]],
        ];
        $statements = [];
        foreach ($data as $rows) {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetchAll')->willReturn($rows);
            $statements[] = $statement;
        }

        $pdo = $this->createMock(PDO::class);
        $queries = [];
        $index = 0;
        $pdo->expects(self::exactly(3))
            ->method('query')
            ->willReturnCallback(
                static function (string $sql) use (&$queries, &$index, $statements): PDOStatement {
                    $queries[] = $sql;
                    return $statements[$index++];
                },
            );

        $overview = (new AdminOverviewRepository($pdo))->pending();

        self::assertSame([['id' => 2, 'email' => 'review@example.com']], $overview['users']);
        self::assertSame([['id' => 3, 'label' => 'Client A',
            'owner_email' => 'owner@example.com']], $overview['clients']);
        self::assertSame([['id' => 4, 'client_label' => 'Client A',
            'address' => '2001:db8::25']], $overview['ips']);

        foreach ($queries as $sql) {
            self::assertStringStartsWith('SELECT ', trim($sql));
            self::assertStringContainsString('LIMIT 50', $sql);
            self::assertStringNotContainsString('api_credentials', $sql);
            self::assertStringNotContainsString('password_hash', $sql);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNonAdministratorCannotTriggerPendingQueries(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $session->authenticate(9);

        $accounts = $this->createStub(LoginAccountRepository::class);
        $accounts->method('isEligible')->willReturn(true);

        $admins = $this->createStub(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('query');

        $limits = $this->createStub(LoginAttemptLimiter::class);
        $events = $this->createStub(SecurityEventRecorder::class);
        $auth = new BrowserAuthController(
            $session,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                $limits,
                $events,
            ),
            $accounts,
            $admins,
        );

        $adapter = new PrivateBrowserAdapter(
            $session, $auth, $accounts, $admins, new AdminOverviewRepository($pdo),
        );

        $response = $adapter->dispatch(
            'GET', '/admin', true, null, '192.0.2.4', str_repeat('a', 32), null,
        );

        self::assertSame(403, $response['status']);
        self::assertStringNotContainsString('review@example.com', $response['body']);
        self::assertTrue(session_destroy());
    }
}
