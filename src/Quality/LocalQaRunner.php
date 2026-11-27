<?php

declare(strict_types=1);

namespace OPGateway\Quality;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\SchemaReadiness;
use OPGateway\Http\OwnerOverviewRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Native PHP, dependency-free local QA. Never modifies the configured DB.
 *
 * Do not add write probes, test-user creation, email delivery, PHPUnit or
 * shell/process execution to this web-triggered report.
 */
final class LocalQaRunner
{
    /**
     * @return array{checks:list<array{name:string,status:string,detail:string}>,
     *     passed:int,failed:int,skipped:int}
     */
    public function run(PDO $pdo): array
    {
        $checks = [];
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();

        try {
            $checks[] = $this->check('PHP runtime and extensions', static function (): string {
                if (PHP_VERSION_ID < 80300) {
                    throw new RuntimeException('Unsupported PHP runtime.');
                }
                foreach (['pdo_mysql', 'curl', 'json', 'openssl'] as $extension) {
                    if (!extension_loaded($extension)) {
                        throw new RuntimeException('Missing required extension.');
                    }
                }
                return 'PHP 8.3+ and required PHP extensions are available.';
            });

            $checks[] = $this->check('PHP source syntax', static function (): string {
                $root = dirname(__DIR__, 2);
                $parsed = 0;
                foreach (['src', 'public', 'tests', 'tests-integration', 'scripts'] as $directory) {
                    $location = $root . '/' . $directory;
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($location),
                    );
                    foreach ($iterator as $file) {
                        if (!$file->isFile() || $file->getExtension() !== 'php'
                            || is_link($file->getPathname())) {
                            continue;
                        }
                        $source = file_get_contents($file->getPathname());
                        if (!is_string($source)) {
                            throw new RuntimeException('Unreadable PHP source.');
                        }
                        // TOKEN_PARSE invokes PHP's parser without evaluating code,
                        // shelling out, loading PHPUnit, or executing test fixtures.
                        token_get_all($source, TOKEN_PARSE);
                        $parsed++;
                    }
                }
                if ($parsed === 0) {
                    throw new RuntimeException('No PHP source files were found.');
                }
                return $parsed . ' PHP files parsed successfully without execution.';
            });

            $checks[] = $this->check('Migration history', static function () use ($pdo): string {
                $catalog = MigrationCatalog::all();
                $status = (new SchemaReadiness($pdo))->inspect($catalog);
                if (count($catalog) !== 9 || !$status['ready']
                    || count($status['completed']) !== 9 || $status['missing'] !== []) {
                    throw new RuntimeException('Schema migration history incomplete.');
                }
                return 'All 9 recorded migrations are complete and verified.';
            });

            $checks[] = $this->check('Required application tables', static function () use ($pdo): string {
                $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                foreach ([
                    'users', 'roles', 'user_roles', 'api_clients',
                    'api_credentials', 'client_ip_whitelists',
                    'schema_migrations', 'provider_connections',
                ] as $table) {
                    if (!in_array($table, $tables, true)) {
                        throw new RuntimeException('An application table is missing.');
                    }
                }
                return 'Required identity, client, credential and IP tables exist.';
            });

            $checks[] = $this->check('Ownership foreign keys', static function () use ($pdo): string {
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
                $references = [];
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $references[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] =
                        $row['REFERENCED_TABLE_NAME'] . '.' . $row['REFERENCED_COLUMN_NAME'];
                }
                foreach ([
                    'api_clients.owner_user_id' => 'users.id',
                    'api_credentials.api_client_id' => 'api_clients.id',
                    'client_ip_whitelists.api_client_id' => 'api_clients.id',
                    'provider_connections.owner_user_id' => 'users.id',
                ] as $field => $expected) {
                    if (($references[$field] ?? null) !== $expected) {
                        throw new RuntimeException('Invalid ownership foreign key.');
                    }
                }
                return 'All 4 ownership relationships are enforced by MySQL.';
            });

            $repository = new OwnerOverviewRepository($pdo);
            $owners = $pdo->query(<<<'SQL'
SELECT u.id FROM users AS u
WHERE u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
  AND EXISTS (SELECT 1 FROM api_clients AS c WHERE c.owner_user_id = u.id)
ORDER BY u.id LIMIT 2
SQL)->fetchAll(PDO::FETCH_COLUMN);

            $checks[] = $this->check('Existing owner resource scope', function () use (
                $repository, $pdo, $owners,
            ): string {
                if ($owners === []) {
                    return 'SKIP: No eligible local owner with an API client exists.';
                }
                $view = $repository->forUser((int) $owners[0]);
                if (!is_array($view)) {
                    throw new RuntimeException('Eligible owner view unavailable.');
                }
                $this->verifyResources($pdo, (int) $owners[0], $view);
                return 'Displayed API clients, credentials and IPs belong to the owner.';
            });

            $checks[] = $this->check('Two-owner isolation', function () use (
                $repository, $pdo, $owners,
            ): string {
                if (count($owners) < 2) {
                    return 'SKIP: Two eligible owners with API clients are not present.';
                }
                $one = $repository->forUser((int) $owners[0]);
                $two = $repository->forUser((int) $owners[1]);
                if (!is_array($one) || !is_array($two)) {
                    throw new RuntimeException('Owner dashboard unavailable.');
                }
                $this->verifyResources($pdo, (int) $owners[0], $one);
                $this->verifyResources($pdo, (int) $owners[1], $two);
                foreach (['clients', 'credentials', 'ips'] as $kind) {
                    $a = array_column($one[$kind], 'id');
                    $b = array_column($two[$kind], 'id');
                    if (array_intersect($a, $b) !== []) {
                        throw new RuntimeException('Cross-owner resources leaked.');
                    }
                }
                return 'Two existing owners have disjoint visible resources.';
            });

            $checks[] = $this->check('Ineligible account isolation', static function () use (
                $repository, $pdo,
            ): string {
                $account = $pdo->query(<<<'SQL'
SELECT id FROM users
WHERE status <> 'active' OR email_verified_at IS NULL OR approved_at IS NULL
ORDER BY id LIMIT 1
SQL)->fetchColumn();
                if ($account === false) {
                    return 'SKIP: No ineligible local account is available.';
                }
                if ($repository->forUser((int) $account) !== null) {
                    throw new RuntimeException('Ineligible owner view was available.');
                }
                return 'Inactive or unapproved accounts cannot read owner dashboard data.';
            });

            $pass = count(array_filter($checks, static fn (array $x): bool => $x['status'] === 'pass'));
            $fail = count(array_filter($checks, static fn (array $x): bool => $x['status'] === 'fail'));
            $skip = count($checks) - $pass - $fail;

            return ['checks' => $checks, 'passed' => $pass, 'failed' => $fail, 'skipped' => $skip];
        } finally {
            // READ ONLY is enforced by MySQL, even if a future check errs.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /** @param callable():string $callback
     *  @return array{name:string,status:string,detail:string}
     */
    private function check(string $name, callable $callback): array
    {
        try {
            $detail = $callback();
            if (str_starts_with($detail, 'SKIP:')) {
                return ['name' => $name, 'status' => 'skip', 'detail' => substr($detail, 6)];
            }
            return ['name' => $name, 'status' => 'pass', 'detail' => $detail];
        } catch (Throwable) {
            // Never expose SQL, stack traces, account details, or credentials in HTML.
            return ['name' => $name, 'status' => 'fail',
                'detail' => 'Check failed. Review local application diagnostics.'];
        }
    }

    /** @param array<string,mixed> $view */
    private function verifyResources(PDO $pdo, int $ownerId, array $view): void
    {
        $queries = [
            'clients' => 'SELECT owner_user_id FROM api_clients WHERE id = ?',
            'credentials' => 'SELECT c.owner_user_id FROM api_credentials AS k '
                . 'JOIN api_clients AS c ON c.id = k.api_client_id WHERE k.id = ? AND c.id = ?',
            'ips' => 'SELECT c.owner_user_id FROM client_ip_whitelists AS ip '
                . 'JOIN api_clients AS c ON c.id = ip.api_client_id WHERE ip.id = ? AND c.id = ?',
        ];
        foreach ($queries as $kind => $sql) {
            if (!isset($view[$kind]) || !is_array($view[$kind])) {
                throw new RuntimeException('Unexpected owner overview shape.');
            }
            $statement = $pdo->prepare($sql);
            foreach ($view[$kind] as $row) {
                if (!is_array($row) || !isset($row['id'])
                    || ($kind !== 'clients' && !isset($row['client_id']))) {
                    throw new RuntimeException('Unexpected resource row.');
                }
                $params = [$row['id']];
                if ($kind !== 'clients') {
                    $params[] = $row['client_id'];
                }
                $statement->execute($params);
                $related = $statement->fetchColumn();
                if ($related === false || (int) $related !== $ownerId
                    || isset($row['secret_hash']) || isset($row['password_hash'])
                    || isset($row['password_cipher'])) {
                    throw new RuntimeException('Owner isolation mismatch.');
                }
            }
        }
    }
}
