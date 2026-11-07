<?php

declare(strict_types=1);

namespace OPGateway\Clients;

use InvalidArgumentException;
use PDO;

/**
 * Owner-scoped client management and verified administrator approval.
 *
 * This internal service is not an HTTP endpoint. Browser controllers must
 * enforce session authentication, authorization, CSRF and rate limiting.
 * Changes requiring administrator approval are rejected by default.
 */
final readonly class ClientManagementService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Create a disabled client. No API key or network authorization is granted.
     * @return int|null New identifier, or null if owner is ineligible.
     */
    public function create(int $ownerId, string $label): ?int
    {
        $label = trim($label);
        if ($ownerId < 1 || $label === '' || strlen($label) > 128
            || preg_match('/[\x00-\x1F\x7F]/', $label)) {
            throw new InvalidArgumentException('Invalid client registration.');
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO api_clients (owner_user_id, label, status, created_at, updated_at)
SELECT id, ?, 'disabled', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
FROM users
WHERE id = ?
  AND status = 'active'
  AND email_verified_at IS NOT NULL
  AND approved_at IS NOT NULL
SQL);
        $stmt->execute([$label, $ownerId]);

        return $stmt->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /**
     * Activate an explicitly identified client only after administrator review.
     * The administrator must be active, verified and already assigned role admin.
     */
    public function activate(int $adminId, int $clientId): bool
    {
        if ($adminId < 1 || $clientId < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE api_clients AS c
JOIN users AS owner ON owner.id = c.owner_user_id
SET c.status = 'active', c.updated_at = UTC_TIMESTAMP(6)
WHERE c.id = ?
  AND c.status = 'disabled'
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
  AND EXISTS (
    SELECT 1 FROM users AS admin
    JOIN user_roles AS ur ON ur.user_id = admin.id
    JOIN roles AS r ON r.id = ur.role_id
    WHERE admin.id = ?
      AND admin.status = 'active'
      AND admin.email_verified_at IS NOT NULL
      AND admin.approved_at IS NOT NULL
      AND r.role_key = 'admin'
  )
SQL);
        $stmt->execute([$clientId, $adminId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Disable a client as its owner. Re-enabling always requires admin review.
     * Existing credentials become unusable while the client is disabled.
     */
    public function disable(int $ownerId, int $clientId): bool
    {
        if ($ownerId < 1 || $clientId < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE api_clients AS c
JOIN users AS u ON u.id = c.owner_user_id
SET c.status = 'disabled', c.updated_at = UTC_TIMESTAMP(6)
WHERE c.id = ?
  AND u.id = ?
  AND c.status = 'active'
  AND u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
SQL);
        $stmt->execute([$clientId, $ownerId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Submit one exact IPv4/IPv6 address for an owned client.
     * The record starts disabled and cannot bypass administrator approval.
     */
    public function requestIp(int $ownerId, int $clientId, string $address): bool
    {
        if ($ownerId < 1 || $clientId < 1) {
            return false;
        }

        $binary = IpEntry::pack($address);
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO client_ip_whitelists (api_client_id, ip_address, enabled, created_at)
SELECT c.id, ?, 0, UTC_TIMESTAMP(6)
FROM api_clients AS c
JOIN users AS u ON u.id = c.owner_user_id
WHERE c.id = ?
  AND u.id = ?
  AND u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
  AND c.status IN ('active', 'disabled')
SQL);
        $stmt->bindValue(1, $binary, PDO::PARAM_LOB);
        $stmt->bindValue(2, $clientId, PDO::PARAM_INT);
        $stmt->bindValue(3, $ownerId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** Approve a pending IP only for an active client and eligible owner. */
    public function approveIp(int $adminId, int $ipEntryId): bool
    {
        if ($adminId < 1 || $ipEntryId < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE client_ip_whitelists AS ip
JOIN api_clients AS c ON c.id = ip.api_client_id
JOIN users AS owner ON owner.id = c.owner_user_id
SET ip.enabled = 1, ip.approved_at = UTC_TIMESTAMP(6)
WHERE ip.id = ?
  AND ip.enabled = 0
  AND ip.approved_at IS NULL
  AND c.status = 'active'
  AND owner.status = 'active'
  AND owner.email_verified_at IS NOT NULL
  AND owner.approved_at IS NOT NULL
  AND EXISTS (
    SELECT 1 FROM users AS admin
    JOIN user_roles AS ur ON ur.user_id = admin.id
    JOIN roles AS r ON r.id = ur.role_id
    WHERE admin.id = ?
      AND admin.status = 'active'
      AND admin.email_verified_at IS NOT NULL
      AND admin.approved_at IS NOT NULL
      AND r.role_key = 'admin'
  )
SQL);
        $stmt->execute([$ipEntryId, $adminId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Revoke approved or pending IP access. A future reapproval requires
     * a separate administrator decision.
     */
    public function revokeIp(int $ownerId, int $clientId, int $ipEntryId): bool
    {
        if ($ownerId < 1 || $clientId < 1 || $ipEntryId < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
DELETE ip FROM client_ip_whitelists AS ip
JOIN api_clients AS c ON c.id = ip.api_client_id
JOIN users AS u ON u.id = c.owner_user_id
WHERE ip.id = ?
  AND c.id = ?
  AND u.id = ?
  AND u.status = 'active'
  AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
SQL);
        $stmt->execute([$ipEntryId, $clientId, $ownerId]);

        return $stmt->rowCount() === 1;
    }
}
