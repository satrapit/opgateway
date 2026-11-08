<?php

declare(strict_types=1);

namespace OPGateway\Security;

use InvalidArgumentException;
use PDO;

/**
 * MySQL-backed, atomic fixed-window throttle for state-changing admin actions.
 *
 * Uses distinct HMAC domain prefixes from login throttling, even when sharing
 * the private LOGIN_RATE_LIMIT_KEY. Never stores an IP or administrator ID.
 * Every valid attempt consumes one IP and one administrator bucket before
 * any approval/activation takes place.
 */
final readonly class PdoAdminActionLimiter implements AdminActionLimiter
{
    private const WINDOW_SECONDS = 900;
    private const MAX_IP_ATTEMPTS = 40;
    private const MAX_ADMIN_ATTEMPTS = 20;

    public function __construct(private PDO $pdo, private string $pepper)
    {
        if (strlen($pepper) < 32) {
            throw new InvalidArgumentException('Admin action limiter requires a random 256-bit key.');
        }
    }

    public function allow(int $administratorId, string $sourceIp): bool
    {
        $packed = @inet_pton($sourceIp);
        if ($administratorId < 1 || $packed === false || !in_array(strlen($packed), [4, 16], true)) {
            return false;
        }

        $window = intdiv(time(), self::WINDOW_SECONDS);
        $ipHash = hash_hmac('sha256', 'admin:action:ip:' . bin2hex($packed), $this->pepper, true);
        $adminHash = hash_hmac('sha256', 'admin:action:user:' . $administratorId, $this->pepper, true);

        // Consume both buckets even if one has reached its limit.
        $ipCount = $this->reserve($ipHash, $window);
        $adminCount = $this->reserve($adminHash, $window);

        return $ipCount <= self::MAX_IP_ATTEMPTS && $adminCount <= self::MAX_ADMIN_ATTEMPTS;
    }

    private function reserve(string $bucket, int $window): int
    {
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO login_rate_limits (bucket_hash, window_number, attempts)
VALUES (?, ?, 1)
ON DUPLICATE KEY UPDATE attempts = attempts + 1
SQL);
        $insert->bindValue(1, $bucket, PDO::PARAM_LOB);
        $insert->bindValue(2, $window, PDO::PARAM_INT);
        $insert->execute();

        $read = $this->pdo->prepare(<<<'SQL'
SELECT attempts FROM login_rate_limits WHERE bucket_hash = ? AND window_number = ?
SQL);
        $read->bindValue(1, $bucket, PDO::PARAM_LOB);
        $read->bindValue(2, $window, PDO::PARAM_INT);
        $read->execute();

        $count = $read->fetchColumn();
        if ($count === false) {
            throw new \RuntimeException('Administrator action limiter storage is unavailable.');
        }

        return (int) $count;
    }
}
