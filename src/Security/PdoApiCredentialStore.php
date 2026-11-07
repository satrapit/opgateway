<?php

declare(strict_types=1);

namespace OPGateway\Security;

use PDO;

/**
 * SQL-enforced owner-scoped issuance, credential lookup and revocation.
 *
 * The database stores only a public prefix and SHA-256 digest of a 256-bit
 * random secret. Never persist the full bearer value or log query bindings.
 */
final readonly class PdoApiCredentialStore implements ApiCredentialStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createForOwner(int $actorUserId, int $clientId, string $prefix, string $digest): bool
    {
        if ($actorUserId < 1 || $clientId < 1 || preg_match('/^opg_[0-9a-f]{16}$/D', $prefix) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO api_credentials (api_client_id, key_prefix, secret_hash, created_at)
SELECT c.id, ?, ?, UTC_TIMESTAMP(6)
FROM api_clients AS c
JOIN users AS u ON u.id = c.owner_user_id
WHERE c.id = ?
  AND u.id = ?
  AND c.status = 'active'
  AND u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
SQL);
        $statement->execute([$prefix, $digest, $clientId, $actorUserId]);
        return $statement->rowCount() === 1;
    }

    public function findActiveByPrefix(string $prefix): ?array
    {
        if (preg_match('/^opg_[0-9a-f]{16}$/D', $prefix) !== 1) {
            return null;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT c.id AS client_id, k.secret_hash AS digest
FROM api_credentials AS k
JOIN api_clients AS c ON c.id = k.api_client_id
JOIN users AS u ON u.id = c.owner_user_id
WHERE k.key_prefix = ?
  AND k.revoked_at IS NULL
  AND (k.expires_at IS NULL OR k.expires_at > UTC_TIMESTAMP(6))
  AND c.status = 'active'
  AND u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
LIMIT 1
SQL);
        $statement->execute([$prefix]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !isset($row['client_id'], $row['digest'])) {
            return null;
        }

        return ['client_id' => (int) $row['client_id'], 'digest' => (string) $row['digest']];
    }

    public function revokeForOwner(int $actorUserId, int $clientId, int $credentialId): bool
    {
        if ($actorUserId < 1 || $clientId < 1 || $credentialId < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE api_credentials AS k
JOIN api_clients AS c ON c.id = k.api_client_id
JOIN users AS u ON u.id = c.owner_user_id
SET k.revoked_at = UTC_TIMESTAMP(6)
WHERE k.id = ?
  AND c.id = ?
  AND u.id = ?
  AND u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
  AND k.revoked_at IS NULL
SQL);
        $statement->execute([$credentialId, $clientId, $actorUserId]);
        return $statement->rowCount() === 1;
    }
}
