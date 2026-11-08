<?php

declare(strict_types=1);

namespace OPGateway\Http;

use PDO;
use RuntimeException;

/**
 * Bounded, read-only review queues for the authenticated administrator.
 *
 * Caller must check the current user and administrator role before invoking
 * this repository. No client credentials, password hashes or tokens are read.
 * Query results are rendered with HTML escaping, never placed in logs.
 */
final readonly class AdminOverviewRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   users:list<array{id:int,email:string}>,
     *   owners:list<array{id:int,email:string}>,
     *   clients:list<array{id:int,label:string,status:string,owner_email:string}>,
     *   ips:list<array{id:int,client_label:string,address:string}>,
     *   approved_ips:list<array{id:int,client_label:string,address:string}>
     * }
     */
    public function pending(): array
    {
        $users = $this->rows(<<<'SQL'
SELECT id, email
FROM users
WHERE status = 'pending' AND email_verified_at IS NOT NULL
ORDER BY id ASC
LIMIT 50
SQL);

        $clients = $this->rows(<<<'SQL'
SELECT c.id, c.label, c.status, owner.email AS owner_email
FROM api_clients AS c
JOIN users AS owner ON owner.id = c.owner_user_id
WHERE c.status IN ('active', 'disabled')
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
ORDER BY c.id ASC
LIMIT 50
SQL);

        $ips = $this->rows(<<<'SQL'
SELECT ip.id, ip.ip_address, c.label AS client_label
FROM client_ip_whitelists AS ip
JOIN api_clients AS c ON c.id = ip.api_client_id
JOIN users AS owner ON owner.id = c.owner_user_id
WHERE ip.enabled = 0
  AND ip.approved_at IS NULL
  AND c.status = 'active'
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
ORDER BY ip.id ASC
LIMIT 50
SQL);

        $owners = $this->rows(<<<'SQL'
SELECT id, email
FROM users
WHERE status = 'active'
  AND email_verified_at IS NOT NULL
  AND approved_at IS NOT NULL
ORDER BY id ASC
LIMIT 50
SQL);

        $approvedIps = $this->rows(<<<'SQL'
SELECT ip.id, ip.ip_address, c.label AS client_label
FROM client_ip_whitelists AS ip
JOIN api_clients AS c ON c.id = ip.api_client_id
WHERE ip.enabled = 1
  AND ip.approved_at IS NOT NULL
  AND c.status IN ('active', 'disabled')
ORDER BY ip.id ASC
LIMIT 50
SQL);

        $pendingIps = [];
        foreach ($ips as $row) {
            $address = @inet_ntop((string) $row['ip_address']);
            if ($address === false) {
                throw new RuntimeException('Invalid stored IP address.');
            }
            $pendingIps[] = [
                'id' => (int) $row['id'],
                'client_label' => (string) $row['client_label'],
                'address' => $address,
            ];
        }

        $activeIps = [];
        foreach ($approvedIps as $row) {
            $address = @inet_ntop((string) $row['ip_address']);
            if ($address === false) {
                throw new RuntimeException('Invalid stored IP address.');
            }
            $activeIps[] = [
                'id' => (int) $row['id'],
                'client_label' => (string) $row['client_label'],
                'address' => $address,
            ];
        }

        return [
            'users' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'email' => (string) $row['email'],
                ],
                $users,
            ),
            'owners' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'email' => (string) $row['email'],
                ],
                $owners,
            ),
            'clients' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'label' => (string) $row['label'],
                    'status' => (string) $row['status'],
                    'owner_email' => (string) $row['owner_email'],
                ],
                $clients,
            ),
            'ips' => $pendingIps,
            'approved_ips' => $activeIps,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $query): array
    {
        $statement = $this->pdo->query($query);
        if ($statement === false) {
            throw new RuntimeException('Administrator review query failed.');
        }
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
