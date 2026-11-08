<?php

declare(strict_types=1);

namespace OPGateway\Http;

use PDO;
use RuntimeException;

/**
 * Bounded, owner-scoped reads for the API credential dashboard.
 *
 * Includes public key prefixes and revocation status, but deliberately
 * excludes secret_hash, passwords and token bodies. Account eligibility
 * is rechecked on each request; all ownership filters live in SQL.
 */
final readonly class OwnerOverviewRepository implements OwnerOverviewProvider
{
    public function __construct(private PDO $pdo)
    {
    }

    public function eligibleEmail(int $ownerId): ?string
    {
        if ($ownerId < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT email FROM users
WHERE id = ? AND status = 'active'
  AND email_verified_at IS NOT NULL
  AND approved_at IS NOT NULL
LIMIT 1
SQL);
        $statement->execute([$ownerId]);
        $email = $statement->fetchColumn();

        return is_string($email) ? $email : null;
    }

    public function forUser(int $ownerId): ?array
    {
        $email = $this->eligibleEmail($ownerId);
        if ($email === null) {
            return null;
        }

        $clients = $this->fetch(<<<'SQL'
SELECT c.id, c.label, c.status
FROM api_clients AS c
JOIN users AS owner ON owner.id = c.owner_user_id
WHERE c.owner_user_id = ?
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
ORDER BY c.id ASC LIMIT 50
SQL, $ownerId);

        $credentials = $this->fetch(<<<'SQL'
SELECT k.id, k.api_client_id AS client_id, k.key_prefix AS prefix,
       k.revoked_at, k.expires_at
FROM api_credentials AS k
JOIN api_clients AS c ON c.id = k.api_client_id
JOIN users AS owner ON owner.id = c.owner_user_id
WHERE c.owner_user_id = ?
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
ORDER BY k.id DESC LIMIT 100
SQL, $ownerId);

        $ips = $this->fetch(<<<'SQL'
SELECT ip.id, ip.api_client_id AS client_id, ip.ip_address, ip.enabled,
       ip.approved_at
FROM client_ip_whitelists AS ip
JOIN api_clients AS c ON c.id = ip.api_client_id
JOIN users AS owner ON owner.id = c.owner_user_id
WHERE c.owner_user_id = ?
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
ORDER BY ip.id DESC LIMIT 100
SQL, $ownerId);

        $listedIps = [];
        foreach ($ips as $row) {
            $address = @inet_ntop((string) $row['ip_address']);
            if ($address === false) {
                throw new RuntimeException('Invalid stored IP address.');
            }
            $listedIps[] = [
                'id' => (int) $row['id'],
                'client_id' => (int) $row['client_id'],
                'address' => $address,
                'enabled' => (int) $row['enabled'] === 1 && $row['approved_at'] !== null,
            ];
        }

        return [
            'email' => $email,
            'clients' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'status' => (string) $row['status'],
            ], $clients),
            'credentials' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'client_id' => (int) $row['client_id'],
                'prefix' => (string) $row['prefix'],
                'revoked' => $row['revoked_at'] !== null
                    || ($row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') <= time()),
            ], $credentials),
            'ips' => $listedIps,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fetch(string $query, int $ownerId): array
    {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$ownerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
