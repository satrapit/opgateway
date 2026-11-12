<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;
use PDO;

/** User-owned first and last names, kept in the existing users table. */
final readonly class PdoUserProfile
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{first_name:string,last_name:string,complete:bool}|null */
    public function forUser(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT first_name, last_name FROM users
WHERE id = ? AND status = 'active'
AND email_verified_at IS NOT NULL AND approved_at IS NOT NULL LIMIT 1
SQL);
        $statement->execute([$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $first = is_string($row['first_name'] ?? null) ? $row['first_name'] : '';
        $last = is_string($row['last_name'] ?? null) ? $row['last_name'] : '';
        return [
            'first_name' => $first,
            'last_name' => $last,
            'complete' => $first !== '' && $last !== '',
        ];
    }

    public function complete(int $userId): bool
    {
        return $this->forUser($userId)['complete'] ?? false;
    }

    public function save(int $userId, string $firstName, string $lastName): bool
    {
        if ($userId < 1) {
            return false;
        }
        $first = self::cleanName($firstName);
        $last = self::cleanName($lastName);
        if ($first === null || $last === null) {
            throw new InvalidArgumentException('Invalid profile name.');
        }
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE users SET first_name = ?, last_name = ?, updated_at = UTC_TIMESTAMP(6)
WHERE id = ? AND status = 'active'
AND email_verified_at IS NOT NULL AND approved_at IS NOT NULL
SQL);
        $stmt->execute([$first, $last, $userId]);
        return $stmt->rowCount() === 1 || $this->complete($userId);
    }

    public static function cleanName(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 100
            || preg_match('//u', $value) !== 1
            || preg_match('/[\p{C}<>]/u', $value) === 1) {
            return null;
        }
        return $value;
    }
}
