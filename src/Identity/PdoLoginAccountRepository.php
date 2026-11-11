<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use PDO;

/**
 * Fail-closed login eligibility: verified, approved and active user required.
 * Parameterized SQL and no user-controlled query fragments.
 */
final readonly class PdoLoginAccountRepository implements LoginAccountRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findEligibleByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, password_hash, auth_session_version
FROM users
WHERE email = ?
  AND status = 'active'
  AND email_verified_at IS NOT NULL
  AND approved_at IS NOT NULL
LIMIT 1
SQL);
        $statement->execute([$email]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $hash = $row['password_hash'];
        $version = (int) $row['auth_session_version'];
        if (($hash !== null && !is_string($hash)) || $version < 1) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'password_hash' => $hash,
            'session_stamp' => AuthenticationSessionVersion::stamp($version),
        ];
    }

    public function isEligible(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM users
WHERE id = ?
  AND status = 'active'
  AND email_verified_at IS NOT NULL
  AND approved_at IS NOT NULL
LIMIT 1
SQL);
        $statement->execute([$userId]);
        return $statement->fetchColumn() !== false;
    }
    /** Reject ineligible accounts and return the current revocation marker. */
    public function currentSessionStamp(int $userId): ?string
    {
        if ($userId < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT auth_session_version FROM users
WHERE id = ? AND status = 'active'
  AND email_verified_at IS NOT NULL AND approved_at IS NOT NULL
LIMIT 1
SQL);
        $statement->execute([$userId]);
        $version = $statement->fetchColumn();
        return (is_int($version) || is_string($version)) && (int) $version > 0
            ? AuthenticationSessionVersion::stamp((int) $version) : null;
    }
}
