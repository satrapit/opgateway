<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;
use PDO;

/**
 * Google identities are keyed only by Google's stable `sub`, never email.
 * Every login lookup rechecks the local user's active/approved/verified state.
 */
final readonly class PdoGoogleIdentityStore
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{id:int,password_hash:string}|null */
    public function eligibleBySubject(string $subject): ?array
    {
        if (!self::validSubject($subject)) {
            return null;
        }
        $query = $this->pdo->prepare(<<<'SQL'
SELECT u.id, u.password_hash FROM google_identities g
JOIN users u ON u.id = g.user_id
WHERE g.google_sub = ? AND u.status = 'active'
AND u.email_verified_at IS NOT NULL AND u.approved_at IS NOT NULL
LIMIT 1
SQL);
        $query->execute([$subject]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && (int) $row['id'] > 0
            ? ['id' => (int) $row['id'], 'password_hash' => (string) $row['password_hash']]
            : null;
    }

    /** @return array{email:string}|null */
    public function linked(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $query = $this->pdo->prepare(
            'SELECT google_email FROM google_identities WHERE user_id = ? LIMIT 1',
        );
        $query->execute([$userId]);
        $email = $query->fetchColumn();
        return is_string($email) ? ['email' => $email] : null;
    }

    /** Requires current local session and fresh password recheck by caller. */
    public function link(int $userId, string $subject, string $email): void
    {
        if ($userId < 1 || !self::validSubject($subject)
            || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid Google identity.');
        }
        $query = $this->pdo->prepare(<<<'SQL'
INSERT INTO google_identities (user_id, google_sub, google_email, linked_at)
VALUES (?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        // Both unique constraints deny linking another user's Google identity,
        // or silently overwriting an existing account connection.
        $query->execute([$userId, $subject, strtolower($email)]);
    }

    public function unlink(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Invalid account.');
        }
        $query = $this->pdo->prepare('DELETE FROM google_identities WHERE user_id = ?');
        $query->execute([$userId]);
    }

    private static function validSubject(string $subject): bool
    {
        return $subject !== '' && strlen($subject) <= 255
            && preg_match('/^[\\x21-\\x7E]+$/D', $subject) === 1;
    }
}
