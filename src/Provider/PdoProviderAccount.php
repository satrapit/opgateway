<?php

declare(strict_types=1);

namespace OPGateway\Provider;

use OPGateway\Security\SealedSecret;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * A single Openprovider reseller connection per eligible user. Both the
 * password and cached upstream bearer are authenticated ciphertext.
 *
 * No decrypted credentials or bearer values are returned to browser views.
 * The caller must authorize the authenticated owner before any write.
 */
final readonly class PdoProviderAccount
{
    public function __construct(private PDO $pdo, private SealedSecret $secrets)
    {
    }

    public function save(int $ownerId, string $username, string $password): bool
    {
        $username = trim($username);
        if ($ownerId < 1 || $username === '' || strlen($username) > 254
            || preg_match('/[\x00-\x1f\x7f]/', $username)
            || $password === '' || strlen($password) > 1024) {
            throw new InvalidArgumentException('Invalid Openprovider account settings.');
        }
        $encrypted = $this->secrets->encrypt($password, 'provider:' . $ownerId);
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO provider_connections
 (owner_user_id, username, password_cipher, updated_at)
SELECT id, ?, ?, UTC_TIMESTAMP(6)
FROM users
WHERE id = ? AND status = 'active'
 AND email_verified_at IS NOT NULL AND approved_at IS NOT NULL
ON DUPLICATE KEY UPDATE username=VALUES(username),
 password_cipher=VALUES(password_cipher), token_cipher=NULL,
 token_expires_at=NULL, updated_at=UTC_TIMESTAMP(6)
SQL);
        $stmt->execute([$username, $encrypted, $ownerId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array{configured:bool,username:string} */
    public function summary(int $ownerId): array
    {
        if ($ownerId < 1) {
            return ['configured' => false, 'username' => ''];
        }
        $stmt = $this->pdo->prepare(
            'SELECT username FROM provider_connections WHERE owner_user_id = ? LIMIT 1',
        );
        $stmt->execute([$ownerId]);
        $username = $stmt->fetchColumn();
        return [
            'configured' => is_string($username),
            'username' => is_string($username) ? $username : '',
        ];
    }

    /** @return array{owner_id:int,username:string,password:string}|null */
    public function credentialsForClient(int $clientId): ?array
    {
        if ($clientId < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT c.owner_user_id, pc.username, pc.password_cipher
FROM api_clients AS c
JOIN users AS u ON u.id = c.owner_user_id
JOIN provider_connections AS pc ON pc.owner_user_id = u.id
WHERE c.id = ? AND c.status = 'active'
  AND u.status = 'active' AND u.email_verified_at IS NOT NULL
  AND u.approved_at IS NOT NULL
LIMIT 1
SQL);
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $ownerId = (int) $row['owner_user_id'];
        return [
            'owner_id' => $ownerId,
            'username' => (string) $row['username'],
            'password' => $this->secrets->decrypt((string) $row['password_cipher'], 'provider:' . $ownerId),
        ];
    }

    public function getCachedToken(int $ownerId): ?string
    {
        if ($ownerId < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT token_cipher FROM provider_connections
WHERE owner_user_id = ? AND token_cipher IS NOT NULL
AND token_expires_at > DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 60 SECOND)
LIMIT 1
SQL);
        $stmt->execute([$ownerId]);
        $token = $stmt->fetchColumn();
        return is_string($token)
            ? $this->secrets->decrypt($token, 'provider:' . $ownerId)
            : null;
    }

    public function saveToken(int $ownerId, string $token): void
    {
        if ($ownerId < 1 || $token === '' || strlen($token) > 4096
            || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            throw new InvalidArgumentException('Invalid upstream token.');
        }
        $encrypted = $this->secrets->encrypt($token, 'provider:' . $ownerId);
        // Cache for at most 30 minutes regardless of actual Openprovider TTL.
        // A token may expire earlier upstream; an unauthorized response
        // clears it, but is never blindly replayed as a state-changing retry.
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE provider_connections
SET token_cipher = ?, token_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 MINUTE)
WHERE owner_user_id = ?
SQL);
        $stmt->execute([$encrypted, $ownerId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Upstream token cache unavailable.');
        }
    }

    public function clearToken(int $ownerId): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE provider_connections
SET token_cipher = NULL, token_expires_at = NULL
WHERE owner_user_id = ?
SQL);
        $stmt->execute([$ownerId]);
    }
}
