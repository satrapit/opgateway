<?php

declare(strict_types=1);

namespace OPGateway\Google;

use InvalidArgumentException;
use OPGateway\Security\SealedSecret;
use PDO;

/** Google OAuth credentials in the existing settings table, not .env. */
final readonly class PdoGoogleOAuthSettings
{
    public function __construct(private PDO $pdo, private SealedSecret $secrets)
    {
    }

    /** @return array{client_id:string,secret_set:bool} */
    public function summary(): array
    {
        $row = $this->rows();
        return ['client_id' => $row['google.client_id'] ?? '',
            'secret_set' => isset($row['google.client_secret'])];
    }

    /** @return array{client_id:string,client_secret:string}|null */
    public function credentials(): ?array
    {
        $row = $this->rows();
        if (!isset($row['google.client_id'], $row['google.client_secret'])) {
            return null;
        }
        if (!self::validClientId($row['google.client_id'])) {
            return null;
        }
        return ['client_id' => $row['google.client_id'],
            'client_secret' => $this->secrets->decrypt(
                $row['google.client_secret'], 'google:oauth',
            )];
    }

    public function configured(): bool
    {
        $row = $this->rows();
        return isset($row['google.client_id'], $row['google.client_secret'])
            && self::validClientId($row['google.client_id']);
    }

    public function save(string $clientId, string $secret): void
    {
        $clientId = trim($clientId);
        if (!self::validClientId($clientId) || strlen($secret) > 4096) {
            throw new InvalidArgumentException('Invalid Google OAuth credentials.');
        }
        $previous = $this->rows();
        if ($secret === '' && (!isset($previous['google.client_secret'])
            || $clientId !== ($previous['google.client_id'] ?? null))) {
            throw new InvalidArgumentException('Google client changes require a new secret.');
        }
        $cipher = $secret === '' ? $previous['google.client_secret']
            : $this->secrets->encrypt($secret, 'google:oauth');
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO settings (setting_key, setting_value, updated_at)
VALUES ('google.client_id', ?, UTC_TIMESTAMP(6)),
       ('google.client_secret', ?, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
updated_at = UTC_TIMESTAMP(6)
SQL);
        $statement->execute([$clientId, $cipher]);
    }

    public static function validClientId(string $clientId): bool
    {
        return strlen($clientId) <= 255
            && preg_match('/^[0-9a-zA-Z_-]{8,230}\\.apps\\.googleusercontent\\.com$/D', $clientId) === 1;
    }

    /** @return array<string,string> */
    private function rows(): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT setting_key, setting_value FROM settings
WHERE setting_key IN ('google.client_id', 'google.client_secret')
SQL);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return is_array($rows) ? $rows : [];
    }
}
