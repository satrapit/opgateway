<?php

declare(strict_types=1);

namespace OPGateway\Security;

use PDO;

/**
 * Exact binary IPv4/IPv6 match; missing/disabled IPs are always rejected.
 *
 * Source IP must be resolved at the trusted server edge. Raw client-supplied
 * forwarded headers are never acceptable here.
 */
final readonly class ApiClientIpPolicy implements ClientIpAccessPolicy
{
    public function __construct(private PDO $pdo)
    {
    }

    public function allows(int $clientId, string $sourceIp): bool
    {
        if ($clientId < 1) {
            return false;
        }

        $binary = @inet_pton($sourceIp);
        if ($binary === false || !in_array(strlen($binary), [4, 16], true)) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1
FROM api_clients AS c
JOIN users AS owner ON owner.id = c.owner_user_id
JOIN client_ip_whitelists AS w ON w.api_client_id = c.id
WHERE c.id = ?
  AND c.status = 'active'
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
  AND w.enabled = 1
  AND w.approved_at IS NOT NULL
  AND w.ip_address = ?
LIMIT 1
SQL);
        $statement->bindValue(1, $clientId, PDO::PARAM_INT);
        $statement->bindValue(2, $binary, PDO::PARAM_LOB);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }
}
