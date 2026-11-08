<?php

declare(strict_types=1);

namespace OPGateway\Clients;

use PDO;

/**
 * Administrator-managed client/IP onboarding without impersonating an owner.
 *
 * The actor must be a freshly eligible administrator. Every SQL write checks
 * that role in the database in addition to the HTTP session authorization.
 * Created clients and IP entries always begin disabled.
 */
final readonly class AdminClientProvisioningService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Provision a disabled client for an active, verified and approved owner. */
    public function createClient(int $administratorId, int $ownerId, string $label): bool
    {
        $label = trim($label);
        if ($administratorId < 1 || $ownerId < 1 || $label === ''
            || strlen($label) > 128 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO api_clients (owner_user_id, label, status, created_at, updated_at)
SELECT owner.id, ?, 'disabled', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
FROM users AS owner
WHERE owner.id = ?
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
  AND EXISTS (
      SELECT 1 FROM users AS admin
      JOIN user_roles AS ur ON ur.user_id = admin.id
      JOIN roles AS r ON r.id = ur.role_id AND r.role_key = 'admin'
      WHERE admin.id = ?
        AND admin.status = 'active'
        AND admin.email_verified_at IS NOT NULL
        AND admin.approved_at IS NOT NULL
  )
SQL);
        $statement->execute([$label, $ownerId, $administratorId]);
        return $statement->rowCount() === 1;
    }

    /** Request an exact IP for an eligible owner's client; never auto-approve. */
    public function requestIp(int $administratorId, int $clientId, string $address): bool
    {
        if ($administratorId < 1 || $clientId < 1) {
            return false;
        }
        try {
            $packed = IpEntry::pack($address);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO client_ip_whitelists (api_client_id, ip_address, enabled, created_at)
SELECT c.id, ?, 0, UTC_TIMESTAMP(6)
FROM api_clients AS c
JOIN users AS owner ON owner.id = c.owner_user_id
WHERE c.id = ?
  AND c.status IN ('active', 'disabled')
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
  AND EXISTS (
      SELECT 1 FROM users AS admin
      JOIN user_roles AS ur ON ur.user_id = admin.id
      JOIN roles AS r ON r.id = ur.role_id AND r.role_key = 'admin'
      WHERE admin.id = ?
        AND admin.status = 'active'
        AND admin.email_verified_at IS NOT NULL
        AND admin.approved_at IS NOT NULL
  )
SQL);
        $statement->bindValue(1, $packed, PDO::PARAM_LOB);
        $statement->bindValue(2, $clientId, PDO::PARAM_INT);
        $statement->bindValue(3, $administratorId, PDO::PARAM_INT);
        try {
            $statement->execute();
        } catch (\PDOException $exception) {
            // Re-requesting the same exact IP must not surface a server
            // error or override an already approved/denied whitelist row.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $exception;
        }
        return $statement->rowCount() === 1;
    }
    /**
     * Disable an active client. Existing API keys immediately fail their
     * active-client check; reactivation always needs a fresh admin decision.
     */
    public function disableClient(int $administratorId, int $clientId): bool
    {
        if ($administratorId < 1 || $clientId < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE api_clients AS c
SET c.status = 'disabled', c.updated_at = UTC_TIMESTAMP(6)
WHERE c.id = ?
  AND c.status = 'active'
  AND EXISTS (
      SELECT 1 FROM users AS admin
      JOIN user_roles AS ur ON ur.user_id = admin.id
      JOIN roles AS r ON r.id = ur.role_id AND r.role_key = 'admin'
      WHERE admin.id = ?
        AND admin.status = 'active'
        AND admin.email_verified_at IS NOT NULL
        AND admin.approved_at IS NOT NULL
  )
SQL);
        $statement->execute([$clientId, $administratorId]);
        return $statement->rowCount() === 1;
    }

    /**
     * Revoke an exact allowlist entry, removing authorization even when the
     * client still has a valid bearer credential.
     */
    public function revokeIp(int $administratorId, int $ipEntryId): bool
    {
        if ($administratorId < 1 || $ipEntryId < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
DELETE ip
FROM client_ip_whitelists AS ip
WHERE ip.id = ?
  AND EXISTS (
      SELECT 1 FROM users AS admin
      JOIN user_roles AS ur ON ur.user_id = admin.id
      JOIN roles AS r ON r.id = ur.role_id AND r.role_key = 'admin'
      WHERE admin.id = ?
        AND admin.status = 'active'
        AND admin.email_verified_at IS NOT NULL
        AND admin.approved_at IS NOT NULL
  )
SQL);
        $statement->execute([$ipEntryId, $administratorId]);
        return $statement->rowCount() === 1;
    }

}
