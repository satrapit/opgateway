<?php

declare(strict_types=1);

namespace OPGateway\Security;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Persistent, fail-closed throttle specifically for first-admin setup.
 *
 * Reserves one attempt per POST from an allowed IP; even an invalid setup
 * token consumes the quota. No raw IP or token is stored, only an HMAC
 * bucket with a domain-separated prefix.
 *
 * The fixed 15-minute window has a limit of five attempts. PHP/cPanel
 * workers share the same atomic InnoDB counter. Errors must block setup.
 */
final readonly class PdoSetupAttemptLimiter
{
    private const WINDOW_SECONDS = 900;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private PDO $pdo, private string $pepper)
    {
        if (strlen($pepper) !== 32) {
            throw new InvalidArgumentException('A 32-byte setup throttle key is required.');
        }
    }

    public function allow(string $sourceIp): bool
    {
        $packed = @inet_pton($sourceIp);
        if (!is_string($packed) || !in_array(strlen($packed), [4, 16], true)) {
            return false;
        }

        $bucket = hash_hmac(
            'sha256',
            'setup:attempt:ip:' . bin2hex($packed),
            $this->pepper,
            true,
        );
        $window = intdiv(time(), self::WINDOW_SECONDS);

        $increment = $this->pdo->prepare(<<<'SQL'
INSERT INTO login_rate_limits (bucket_hash, window_number, attempts)
VALUES (?, ?, 1)
ON DUPLICATE KEY UPDATE attempts = attempts + 1
SQL);
        $increment->bindValue(1, $bucket, PDO::PARAM_LOB);
        $increment->bindValue(2, $window, PDO::PARAM_INT);
        $increment->execute();

        $fetch = $this->pdo->prepare(<<<'SQL'
SELECT attempts FROM login_rate_limits
WHERE bucket_hash = ? AND window_number = ?
SQL);
        $fetch->bindValue(1, $bucket, PDO::PARAM_LOB);
        $fetch->bindValue(2, $window, PDO::PARAM_INT);
        $fetch->execute();

        $count = $fetch->fetchColumn();
        if ($count === false) {
            throw new RuntimeException('Setup throttle counter unavailable.');
        }

        return (int) $count <= self::MAX_ATTEMPTS;
    }
}
