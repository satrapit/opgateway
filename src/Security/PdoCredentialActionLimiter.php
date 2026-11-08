<?php

declare(strict_types=1);

namespace OPGateway\Security;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Dedicated HMAC counters for account credential mutations.
 *
 * Uses existing login_rate_limits without sharing buckets with admin
 * mutations or password login. Every attempt reserves two counters before
 * a bearer token is minted or revoked. Storage errors fail closed.
 */
final readonly class PdoCredentialActionLimiter implements CredentialActionLimiter
{
    private const WINDOW_SECONDS = 900;
    private const OWNER_LIMIT = 10;
    private const IP_LIMIT = 30;

    public function __construct(private PDO $pdo, private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('Credential limiter key must have 256-bit entropy.');
        }
    }

    public function allow(int $ownerId, string $sourceIp): bool
    {
        $packed = @inet_pton($sourceIp);
        if ($ownerId < 1 || $packed === false || !in_array(strlen($packed), [4, 16], true)) {
            return false;
        }

        $window = intdiv(time(), self::WINDOW_SECONDS);
        $userBucket = hash_hmac('sha256', 'owner:credential:user:' . $ownerId, $this->secret, true);
        $ipBucket = hash_hmac('sha256', 'owner:credential:ip:' . bin2hex($packed), $this->secret, true);

        $userCount = $this->reserve($userBucket, $window);
        $ipCount = $this->reserve($ipBucket, $window);
        return $userCount <= self::OWNER_LIMIT && $ipCount <= self::IP_LIMIT;
    }

    private function reserve(string $bucket, int $window): int
    {
        $update = $this->pdo->prepare(<<<'SQL'
INSERT INTO login_rate_limits (bucket_hash, window_number, attempts)
VALUES (?, ?, 1)
ON DUPLICATE KEY UPDATE attempts = attempts + 1
SQL);
        $update->bindValue(1, $bucket, PDO::PARAM_LOB);
        $update->bindValue(2, $window, PDO::PARAM_INT);
        $update->execute();

        $read = $this->pdo->prepare(<<<'SQL'
SELECT attempts FROM login_rate_limits WHERE bucket_hash = ? AND window_number = ?
SQL);
        $read->bindValue(1, $bucket, PDO::PARAM_LOB);
        $read->bindValue(2, $window, PDO::PARAM_INT);
        $read->execute();

        $count = $read->fetchColumn();
        if ($count === false) {
            throw new RuntimeException('Credential action limiter unavailable.');
        }

        return (int) $count;
    }
}
