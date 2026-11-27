<?php

declare(strict_types=1);

namespace OPGateway\Tests\Integration;

use OPGateway\Config\Environment;
use OPGateway\Database\PdoConnectionFactory;
use OPGateway\Http\OwnerOverviewRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Real-MySQL, read-only checks against the existing local installation.
 *
 * All queries run in a READ ONLY transaction. They never issue credentials,
 * create clients, alter users or copy sensitive account data into test output.
 * Data-dependent checks SKIP when matching existing fixtures are absent.
 */
final class LocalOwnerIsolationReadOnlyTest extends TestCase
{
    public function testOwnershipForeignKeysExistEvenInAnEmptyDataset(): void
    {
        $this->withReadOnlyDatabase(function (PDO $pdo): void {
            $query = $pdo->query(<<<'SQL'
SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND TABLE_NAME IN (
    'api_clients', 'api_credentials', 'client_ip_whitelists',
    'provider_connections'
  )
SQL);
            $foreignKeys = [];
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $foreignKeys[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] =
                    $row['REFERENCED_TABLE_NAME'] . '.' . $row['REFERENCED_COLUMN_NAME'];
            }

            foreach ([
                'api_clients.owner_user_id' => 'users.id',
                'api_credentials.api_client_id' => 'api_clients.id',
                'client_ip_whitelists.api_client_id' => 'api_clients.id',
                'provider_connections.owner_user_id' => 'users.id',
            ] as $column => $reference) {
                self::assertSame($reference, $foreignKeys[$column] ?? null,
                    'Expected ownership foreign-key relationship is missing.');
            }
        });
    }

    public function testExistingOwnerDashboardNeverReturnsAnotherOwnersRows(): void
    {
        $this->withReadOnlyDatabase(function (PDO $pdo): void {
            $owner = $pdo->query(<<<'SQL'
SELECT u.id
FROM users AS u
WHERE u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
  AND EXISTS (SELECT 1 FROM api_clients AS c WHERE c.owner_user_id = u.id)
ORDER BY u.id LIMIT 1
SQL)->fetchColumn();

            if ($owner === false) {
                self::markTestSkipped('No eligible local owner with API clients; no fixture was created.');
            }

            $ownerId = (int) $owner;
            $view = (new OwnerOverviewRepository($pdo))->forUser($ownerId);
            self::assertIsArray($view);
            self::assertNotEmpty($view['clients']);
            $this->assertResourcesOwnedBy($pdo, $ownerId, $view);
        });
    }

    public function testTwoExistingOwnersHaveDisjointClientCredentialAndIpViews(): void
    {
        $this->withReadOnlyDatabase(function (PDO $pdo): void {
            $owners = $pdo->query(<<<'SQL'
SELECT u.id
FROM users AS u
WHERE u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
  AND EXISTS (SELECT 1 FROM api_clients AS c WHERE c.owner_user_id = u.id)
ORDER BY u.id LIMIT 2
SQL)->fetchAll(PDO::FETCH_COLUMN);

            if (count($owners) < 2) {
                self::markTestSkipped(
                    'Two eligible local owners with API clients are required; no test records were created.',
                );
            }

            $repository = new OwnerOverviewRepository($pdo);
            $firstId = (int) $owners[0];
            $secondId = (int) $owners[1];
            $first = $repository->forUser($firstId);
            $second = $repository->forUser($secondId);

            self::assertIsArray($first);
            self::assertIsArray($second);
            $this->assertResourcesOwnedBy($pdo, $firstId, $first);
            $this->assertResourcesOwnedBy($pdo, $secondId, $second);

            foreach (['clients', 'credentials', 'ips'] as $resource) {
                $firstIds = array_map(static fn (array $row): int => $row['id'], $first[$resource]);
                $secondIds = array_map(static fn (array $row): int => $row['id'], $second[$resource]);
                self::assertSame([], array_values(array_intersect($firstIds, $secondIds)),
                    'Two owners must never share visible resource IDs in the same table.');
            }
        });
    }

    public function testIneligibleExistingOwnerCannotReadDashboard(): void
    {
        $this->withReadOnlyDatabase(function (PDO $pdo): void {
            $owner = $pdo->query(<<<'SQL'
SELECT id FROM users
WHERE status <> 'active' OR email_verified_at IS NULL OR approved_at IS NULL
ORDER BY id LIMIT 1
SQL)->fetchColumn();

            if ($owner === false) {
                self::markTestSkipped('No ineligible user exists in local database.');
            }

            self::assertNull((new OwnerOverviewRepository($pdo))->forUser((int) $owner),
                'An ineligible identity must not receive an owner dashboard.');
        });
    }

    /**
     * @param array{email:string,clients:list<array<string,mixed>>,
     *   credentials:list<array<string,mixed>>,ips:list<array<string,mixed>>} $view
     */
    private function assertResourcesOwnedBy(PDO $pdo, int $ownerId, array $view): void
    {
        $checks = [
            'clients' => <<<'SQL'
SELECT owner_user_id FROM api_clients WHERE id = ?
SQL,
            'credentials' => <<<'SQL'
SELECT c.owner_user_id FROM api_credentials AS k
JOIN api_clients AS c ON c.id = k.api_client_id
WHERE k.id = ? AND c.id = ?
SQL,
            'ips' => <<<'SQL'
SELECT c.owner_user_id FROM client_ip_whitelists AS ip
JOIN api_clients AS c ON c.id = ip.api_client_id
WHERE ip.id = ? AND c.id = ?
SQL,
        ];

        foreach ($checks as $kind => $sql) {
            $statement = $pdo->prepare($sql);
            foreach ($view[$kind] as $row) {
                $arguments = [$row['id']];
                if ($kind !== 'clients') {
                    $arguments[] = $row['client_id'];
                }
                $statement->execute($arguments);
                $actualOwner = $statement->fetchColumn();
                self::assertTrue($actualOwner !== false && (int) $actualOwner === $ownerId,
                    'An owner dashboard returned a resource outside its ownership boundary.');
                self::assertArrayNotHasKey('secret_hash', $row);
                self::assertArrayNotHasKey('password_hash', $row);
                self::assertArrayNotHasKey('password_cipher', $row);
            }
        }
    }

    /** @param callable(PDO):void $test */
    private function withReadOnlyDatabase(callable $test): void
    {
        if (getenv('OPGATEWAY_TEST_LOCAL_READONLY') !== '1') {
            self::markTestSkipped('Opt in explicitly with OPGATEWAY_TEST_LOCAL_READONLY=1.');
        }

        $envPath = getenv('OPGATEWAY_LOCAL_DB_ENV');
        if (!is_string($envPath) || $envPath === '') {
            $envPath = dirname(__DIR__) . '/.env';
        }
        $configuration = Environment::load($envPath);
        $host = strtolower($configuration['DB_HOST'] ?? '');
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Non-loopback MySQL connections are refused by this test.');
        }

        $pdo = PdoConnectionFactory::open($configuration);
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
