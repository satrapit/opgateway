<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\OwnerOverviewRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class OwnerOverviewRepositoryTest extends TestCase
{
    public function testDashboardQueriesAreBoundToOwnerAndDoNotReadSecrets(): void
    {
        $email = $this->createStub(PDOStatement::class);
        $email->method('fetchColumn')->willReturn('owner@example.com');
        $client = $this->createStub(PDOStatement::class);
        $client->method('fetchAll')->willReturn([
            ['id' => 3, 'label' => 'Only mine', 'status' => 'active'],
        ]);
        $key = $this->createStub(PDOStatement::class);
        $key->method('fetchAll')->willReturn([
            ['id' => 7, 'client_id' => 3, 'prefix' => 'opg_0123456789abcdef',
                'revoked_at' => null, 'expires_at' => null],
        ]);
        $ips = $this->createStub(PDOStatement::class);
        $ips->method('fetchAll')->willReturn([
            ['id' => 2, 'client_id' => 3, 'ip_address' => inet_pton('2001:db8::1'),
                'enabled' => 1, 'approved_at' => '2026-01-01 00:00:00.000000'],
        ]);
        $prepared = [$email, $client, $key, $ips];
        $queries = [];
        $index = 0;
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(4))->method('prepare')
            ->willReturnCallback(static function (string $sql) use (&$queries, &$index, $prepared): PDOStatement {
                $queries[] = $sql;
                return $prepared[$index++];
            });
        $model = (new OwnerOverviewRepository($pdo))->forUser(17);
        self::assertSame('owner@example.com', $model['email']);
        self::assertSame([['id' => 3, 'label' => 'Only mine', 'status' => 'active']], $model['clients']);
        self::assertSame([['id' => 7, 'client_id' => 3,
            'prefix' => 'opg_0123456789abcdef', 'revoked' => false]], $model['credentials']);
        self::assertSame([['id' => 2, 'client_id' => 3,
            'address' => '2001:db8::1', 'enabled' => true]], $model['ips']);
        foreach (array_slice($queries, 1) as $sql) {
            self::assertStringContainsString('owner_user_id = ?', $sql);
            self::assertStringContainsString('LIMIT ', $sql);
            self::assertStringNotContainsString('secret_hash', $sql);
            self::assertStringNotContainsString('password_hash', $sql);
        }
    }

    public function testIneligibleOwnerNeverTriggersClientOrKeyQueries(): void
    {
        $email = $this->createStub(PDOStatement::class);
        $email->method('fetchColumn')->willReturn(false);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($email);
        self::assertNull((new OwnerOverviewRepository($pdo))->forUser(17));
    }

    public function testInvalidOwnerNeverQueriesStorage(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');
        self::assertNull((new OwnerOverviewRepository($pdo))->eligibleEmail(0));
    }
}
