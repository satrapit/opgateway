<?php

declare(strict_types=1);

namespace OPGateway\Security;

use PDO;
use RuntimeException;

/**
 * Reserve independent IP and normalized-email buckets before signup or
 * verification resend. Counters contain only keyed HMAC digests, never PII.
 */
final readonly class PdoSignupLimiter
{
    public function __construct(private PDO $pdo, private string $pepper)
    {
        if (strlen($pepper) < 32) {
            throw new \InvalidArgumentException('Signup limiter requires a 256-bit key.');
        }
    }

    public function allow(string $sourceIp, string $email): bool
    {
        $binary = @inet_pton($sourceIp);
        if ($binary === false || !in_array(strlen($binary), [4, 16], true)
            || strlen($email) > 254 || $email === '') {
            return false;
        }
        $window = intdiv(time(), 3600);
        $ip = hash_hmac('sha256', 'registration:ip:' . bin2hex($binary), $this->pepper, true);
        $identity = hash_hmac('sha256', 'registration:email:' . strtolower(trim($email)), $this->pepper, true);
        $ipAttempts = $this->count($ip, $window);
        $identityAttempts = $this->count($identity, $window);
        return $ipAttempts <= 10 && $identityAttempts <= 3;
    }

    private function count(string $bucket, int $window): int
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO login_rate_limits (bucket_hash, window_number, attempts)
VALUES (?, ?, 1)
ON DUPLICATE KEY UPDATE attempts = attempts + 1
SQL);
        $stmt->bindValue(1, $bucket, PDO::PARAM_LOB);
        $stmt->bindValue(2, $window, PDO::PARAM_INT);
        $stmt->execute();
        $read = $this->pdo->prepare(<<<'SQL'
SELECT attempts FROM login_rate_limits WHERE bucket_hash = ? AND window_number = ?
SQL);
        $read->bindValue(1, $bucket, PDO::PARAM_LOB);
        $read->bindValue(2, $window, PDO::PARAM_INT);
        $read->execute();
        $count = $read->fetchColumn();
        if ($count === false) {
            throw new RuntimeException('Signup throttle unavailable.');
        }
        return (int) $count;
    }
}
