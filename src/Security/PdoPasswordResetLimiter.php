<?php

declare(strict_types=1);

namespace OPGateway\Security;

use PDO;
use RuntimeException;

/** Separate IP and email HMAC throttles, without storing plaintext identifiers. */
final readonly class PdoPasswordResetLimiter
{
    public function __construct(private PDO $pdo, private string $key)
    {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('A 256-bit rate-limit key is required.');
        }
    }

    public function allow(string $sourceIp, string $email): bool
    {
        $packed = @inet_pton($sourceIp);
        if ($packed === false || !in_array(strlen($packed), [4, 16], true)
            || $email === '' || strlen($email) > 254) {
            return false;
        }
        $window = intdiv(time(), 3600);
        $ipBucket = hash_hmac('sha256', 'password-reset:ip:' . bin2hex($packed), $this->key, true);
        $emailBucket = hash_hmac('sha256', 'password-reset:email:' . strtolower(trim($email)), $this->key, true);
        $ipCount = $this->increment($ipBucket, $window);
        $emailCount = $this->increment($emailBucket, $window);
        return $ipCount <= 10 && $emailCount <= 3;
    }

    private function increment(string $bucket, int $window): int
    {
        $write = $this->pdo->prepare(<<<'SQL'
INSERT INTO login_rate_limits (bucket_hash, window_number, attempts)
VALUES (?, ?, 1)
ON DUPLICATE KEY UPDATE attempts = attempts + 1
SQL);
        $write->bindValue(1, $bucket, PDO::PARAM_LOB);
        $write->bindValue(2, $window, PDO::PARAM_INT);
        $write->execute();
        $read = $this->pdo->prepare(<<<'SQL'
SELECT attempts FROM login_rate_limits WHERE bucket_hash = ? AND window_number = ?
SQL);
        $read->bindValue(1, $bucket, PDO::PARAM_LOB);
        $read->bindValue(2, $window, PDO::PARAM_INT);
        $read->execute();
        $count = $read->fetchColumn();
        if ($count === false) {
            throw new RuntimeException('Reset request throttle unavailable.');
        }
        return (int) $count;
    }
}
