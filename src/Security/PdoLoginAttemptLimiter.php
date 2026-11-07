<?php

declare(strict_types=1);

namespace OPGateway\Security;

use InvalidArgumentException;
use PDO;

/**
 * Fixed-window, atomic, MySQL-backed anti-brute-force guard.
 *
 * Applies separate per-IP and per-identity limits. Bucket keys are HMACs,
 * preventing direct storage of addresses or account identifiers. Each
 * attempt is counted before the password is checked, including successes.
 * The rate limit is fail-closed on any database error.
 */
final readonly class PdoLoginAttemptLimiter implements LoginAttemptLimiter
{
    private const WINDOW_SECONDS = 900;
    private const IP_LIMIT = 30;
    private const IDENTITY_LIMIT = 8;

    public function __construct(private PDO $pdo, private string $pepper)
    {
        if (strlen($pepper) < 32) {
            throw new InvalidArgumentException('A high-entropy rate-limit key is required.');
        }
    }

    public function allow(string $sourceIp, string $email): bool
    {
        $packed = @inet_pton($sourceIp);
        if ($packed === false || !in_array(strlen($packed), [4, 16], true)) {
            return false;
        }

        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 254) {
            return false;
        }

        $window = intdiv(time(), self::WINDOW_SECONDS);
        $ipBucket = hash_hmac('sha256', 'login:ip:' . bin2hex($packed), $this->pepper, true);
        $identityBucket = hash_hmac('sha256', 'login:identity:' . $email, $this->pepper, true);

        // Both attempts are reserved, even if one bucket exceeds its limit.
        // Avoid starting password verification when either threshold is exceeded.
        $ipCount = $this->increment($ipBucket, $window);
        $identityCount = $this->increment($identityBucket, $window);

        return $ipCount <= self::IP_LIMIT && $identityCount <= self::IDENTITY_LIMIT;
    }

    private function increment(string $bucket, int $window): int
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
            throw new \RuntimeException('Rate-limit counter unavailable.');
        }
        return (int) $count;
    }
}
