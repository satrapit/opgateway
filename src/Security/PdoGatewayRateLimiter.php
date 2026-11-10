<?php

declare(strict_types=1);

namespace OPGateway\Security;

use PDO;

/** Distinct 5-minute per-client and per-IP counters for paid API access. */
final readonly class PdoGatewayRateLimiter
{
    public function __construct(private PDO $pdo, private string $key)
    {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('Gateway limiter needs a random 256-bit key.');
        }
    }

    public function allow(int $clientId, string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($clientId < 1 || $packed === false || !in_array(strlen($packed), [4, 16], true)) {
            return false;
        }
        $window = intdiv(time(), 300);
        $byClient = hash_hmac('sha256', 'gateway:client:' . $clientId, $this->key, true);
        $byIp = hash_hmac('sha256', 'gateway:ip:' . bin2hex($packed), $this->key, true);
        $clientCount = $this->reserve($byClient, $window);
        $ipCount = $this->reserve($byIp, $window);
        return $clientCount <= 120 && $ipCount <= 300;
    }

    private function reserve(string $bucket, int $window): int
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO login_rate_limits (bucket_hash, window_number, attempts)
VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE attempts = attempts + 1
SQL);
        $stmt->bindValue(1, $bucket, PDO::PARAM_LOB);
        $stmt->bindValue(2, $window, PDO::PARAM_INT);
        $stmt->execute();
        $stmt = $this->pdo->prepare(
            'SELECT attempts FROM login_rate_limits WHERE bucket_hash = ? AND window_number = ?',
        );
        $stmt->bindValue(1, $bucket, PDO::PARAM_LOB);
        $stmt->bindValue(2, $window, PDO::PARAM_INT);
        $stmt->execute();
        $count = $stmt->fetchColumn();
        if ($count === false) {
            throw new \RuntimeException('Gateway throttle storage unavailable.');
        }
        return (int) $count;
    }
}
