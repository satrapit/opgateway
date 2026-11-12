<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/** Transactional, subject-unique Google identity onboarding and recovery. */
final readonly class PdoGoogleIdentityStore
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Authenticate an already-linked Google subject without creating or
     * joining any local account. This narrow path is retained for a code-only
     * rollback after migration 008, including Google-only users.
     *
     * @return array{status:string,user_id?:int,session_stamp?:string}
     */
    public function loginBySubject(string $subject): array
    {
        if (!self::validSubject($subject)) {
            return ['status' => 'denied'];
        }

        $query = $this->pdo->prepare(<<<'SQL'
SELECT u.id, u.status, u.email_verified_at, u.approved_at, u.auth_session_version
FROM google_identities g JOIN users u ON u.id = g.user_id
WHERE g.google_sub = ? LIMIT 1
SQL);
        $query->execute([$subject]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::loginResult($row) : ['status' => 'denied'];
    }

    /** @return array{status:string,user_id?:int,session_stamp?:string} */
    public function loginOrRegister(
        string $subject, string $email, bool $emailAuthoritative = false,
    ): array
    {
        self::assertIdentity($subject, $email);
        $email = strtolower($email);

        // Unique email/subject constraints serialize concurrent callbacks.
        // Retry once to resolve the row committed by the winning request.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $linked = $this->selectSubject($subject);
                if ($linked !== null) {
                    $this->pdo->commit();
                    return self::loginResult($linked);
                }

                $findEmail = $this->pdo->prepare(<<<'SQL'
SELECT id, status, email_verified_at, approved_at, signup_source,
       auth_session_version
FROM users WHERE email = ? LIMIT 1 FOR UPDATE
SQL);
                $findEmail->execute([$email]);
                $existing = $findEmail->fetch(PDO::FETCH_ASSOC);
                if (is_array($existing)) {
                    if (!$emailAuthoritative) {
                        $this->pdo->commit();
                        return in_array($existing['status'] ?? null, ['active', 'pending'], true)
                            ? ['status' => 'confirmation_required',
                                'user_id' => (int) $existing['id']]
                            : ['status' => 'denied'];
                    }
                    $linked = $this->linkAuthoritativeCollision(
                        $existing, $subject, $email,
                    );
                    $this->pdo->commit();
                    return $linked;
                }

                $create = $this->pdo->prepare(<<<'SQL'
INSERT INTO users (
    email, password_hash, status, email_verified_at, approved_at,
    signup_source, created_at, updated_at
) VALUES (?, NULL, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), 'google',
          UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
SQL);
                $create->execute([$email]);
                $userId = (int) $this->pdo->lastInsertId();
                if ($userId < 1) {
                    throw new \RuntimeException('Google account creation failed.');
                }
                $this->insertIdentity($userId, $subject, $email);
                $this->pdo->commit();
                return [
                    'status' => 'created', 'user_id' => $userId,
                    'session_stamp' => AuthenticationSessionVersion::stamp(1),
                ];
            } catch (PDOException $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ((string) $error->getCode() === '23000' && $attempt === 0) {
                    continue;
                }
                if ((string) $error->getCode() === '23000') {
                    return ['status' => 'denied'];
                }
                throw $error;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        return ['status' => 'denied'];
    }

    /** @return array{email:string,has_password:bool}|null */
    public function linked(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $query = $this->pdo->prepare(<<<'SQL'
SELECT g.google_email, u.password_hash
FROM google_identities g JOIN users u ON u.id = g.user_id
WHERE g.user_id = ? LIMIT 1
SQL);
        $query->execute([$userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && is_string($row['google_email'] ?? null)
            ? [
                'email' => $row['google_email'],
                'has_password' => is_string($row['password_hash'] ?? null)
                    && $row['password_hash'] !== '',
            ]
            : null;
    }

    /** @return array{has_password:bool,session_stamp:string}|null */
    public function securityStatus(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $query = $this->pdo->prepare(<<<'SQL'
SELECT password_hash, auth_session_version FROM users
WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL
AND approved_at IS NOT NULL LIMIT 1
SQL);
        $query->execute([$userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) ($row['auth_session_version'] ?? 0) < 1) {
            return null;
        }
        return [
            'has_password' => is_string($row['password_hash'] ?? null)
                && $row['password_hash'] !== '',
            'session_stamp' => AuthenticationSessionVersion::stamp(
                (int) $row['auth_session_version'],
            ),
        ];
    }

    public function subjectBelongsToUser(string $subject, int $userId): bool
    {
        if (!self::validSubject($subject) || $userId < 1) {
            return false;
        }
        $query = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM google_identities g JOIN users u ON u.id = g.user_id
WHERE g.google_sub = ? AND g.user_id = ? AND u.status = 'active'
AND u.email_verified_at IS NOT NULL AND u.approved_at IS NOT NULL LIMIT 1
SQL);
        $query->execute([$subject, $userId]);
        return $query->fetchColumn() !== false;
    }

    /** Link from an authenticated, password-confirmed account. */
    public function link(int $userId, string $subject, string $email): string
    {
        self::assertIdentity($subject, $email);
        if ($userId < 1) {
            throw new InvalidArgumentException('Invalid Google identity.');
        }
        $this->pdo->beginTransaction();
        try {
            $account = $this->pdo->prepare(<<<'SQL'
SELECT email, auth_session_version FROM users
WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL
AND approved_at IS NOT NULL FOR UPDATE
SQL);
            $account->execute([$userId]);
            $row = $account->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !is_string($row['email'] ?? null)
                || !hash_equals(strtolower($row['email']), strtolower($email))) {
                throw new \DomainException('Google identity does not match account.');
            }
            $this->insertIdentity($userId, $subject, strtolower($email));
            $version = (int) $row['auth_session_version'] + 1;
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE users SET auth_session_version = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?
SQL);
            $update->execute([$version, $userId]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Google link revocation failed.');
            }
            $this->pdo->commit();
            return AuthenticationSessionVersion::stamp($version);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** A local password must remain before Google can be removed. */
    public function unlink(int $userId): bool
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Invalid account.');
        }
        $this->pdo->beginTransaction();
        try {
            $account = $this->pdo->prepare(<<<'SQL'
SELECT password_hash FROM users
WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL
AND approved_at IS NOT NULL FOR UPDATE
SQL);
            $account->execute([$userId]);
            $hash = $account->fetchColumn();
            if (!is_string($hash) || $hash === '') {
                $this->pdo->rollBack();
                return false;
            }
            $delete = $this->pdo->prepare(
                'DELETE FROM google_identities WHERE user_id = ?',
            );
            $delete->execute([$userId]);
            if ($delete->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }
            $revoke = $this->pdo->prepare(<<<'SQL'
UPDATE users SET auth_session_version = auth_session_version + 1,
updated_at = UTC_TIMESTAMP(6) WHERE id = ?
SQL);
            $revoke->execute([$userId]);
            if ($revoke->rowCount() !== 1) {
                throw new \RuntimeException('Google unlink revocation failed.');
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Issue a token bound to the consumed OIDC transaction and intended user. */
    public function issueConfirmation(
        int $userId, string $subject, string $email, string $transactionId,
    ): string {
        self::assertIdentity($subject, $email);
        if ($userId < 1 || preg_match('/^[0-9a-f]{64}$/D', $transactionId) !== 1) {
            throw new InvalidArgumentException('Invalid Google confirmation.');
        }
        $token = bin2hex(random_bytes(32));
        $this->pdo->beginTransaction();
        try {
            $user = $this->pdo->prepare('SELECT email FROM users WHERE id = ? FOR UPDATE');
            $user->execute([$userId]);
            $storedEmail = $user->fetchColumn();
            if (!is_string($storedEmail)
                || !hash_equals(strtolower($storedEmail), strtolower($email))) {
                throw new \DomainException('Google confirmation account changed.');
            }
            $expire = $this->pdo->prepare(<<<'SQL'
UPDATE google_identity_confirmations SET consumed_at = UTC_TIMESTAMP(6)
WHERE user_id = ? AND consumed_at IS NULL
SQL);
            $expire->execute([$userId]);
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO google_identity_confirmations (
    user_id, google_sub, google_email, transaction_hash, token_hash,
    expires_at, created_at
) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 900 SECOND), UTC_TIMESTAMP(6))
SQL);
            $insert->execute([
                $userId, $subject, strtolower($email),
                hash('sha256', $transactionId), hash('sha256', $token),
            ]);
            $this->pdo->commit();
            return $token;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{status:string,user_id?:int,session_stamp?:string} */
    public function confirm(string $token): array
    {
        if (preg_match('/^[0-9a-f]{64}$/iD', $token) !== 1) {
            return ['status' => 'denied'];
        }
        $this->pdo->beginTransaction();
        try {
            $read = $this->pdo->prepare(<<<'SQL'
SELECT c.id, c.user_id, c.google_sub, c.google_email, u.email,
       u.status, u.approved_at, u.auth_session_version
FROM google_identity_confirmations c
JOIN users u ON u.id = c.user_id
WHERE c.token_hash = ? AND c.consumed_at IS NULL
AND c.expires_at > UTC_TIMESTAMP(6) FOR UPDATE
SQL);
            $read->execute([hash('sha256', strtolower($token))]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->rollBack();
                return ['status' => 'denied'];
            }
            if (!is_string($row['email'] ?? null)
                || !hash_equals(strtolower($row['email']), (string) $row['google_email'])) {
                $this->pdo->rollBack();
                return ['status' => 'denied'];
            }
            if (!in_array($row['status'], ['active', 'pending'], true)) {
                $this->pdo->rollBack();
                return ['status' => 'denied'];
            }
            $this->insertIdentity(
                (int) $row['user_id'], (string) $row['google_sub'],
                (string) $row['google_email'],
            );
            $version = (int) $row['auth_session_version'] + 1;
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP(6)),
auth_session_version = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?
SQL);
            $update->execute([$version, $row['user_id']]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Google confirmation update failed.');
            }
            $consume = $this->pdo->prepare(<<<'SQL'
UPDATE google_identity_confirmations SET consumed_at = UTC_TIMESTAMP(6)
WHERE id = ? AND consumed_at IS NULL
SQL);
            $consume->execute([$row['id']]);
            if ($consume->rowCount() !== 1) {
                throw new \RuntimeException('Google confirmation consumption failed.');
            }
            $this->pdo->commit();

            if ($row['status'] === 'active' && $row['approved_at'] !== null) {
                return [
                    'status' => 'authenticated',
                    'user_id' => (int) $row['user_id'],
                    'session_stamp' => AuthenticationSessionVersion::stamp($version),
                ];
            }
            return ['status' => 'pending', 'user_id' => (int) $row['user_id']];
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $error->getCode() === '23000') {
                return ['status' => 'denied'];
            }
            throw $error;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function cancelConfirmation(string $token): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return;
        }
        $cancel = $this->pdo->prepare(<<<'SQL'
UPDATE google_identity_confirmations SET consumed_at = UTC_TIMESTAMP(6)
WHERE token_hash = ? AND consumed_at IS NULL
SQL);
        $cancel->execute([hash('sha256', $token)]);
    }

    /** @return array<string,mixed>|null */
    private function selectSubject(string $subject): ?array
    {
        $query = $this->pdo->prepare(<<<'SQL'
SELECT u.id, u.status, u.email_verified_at, u.approved_at, u.auth_session_version
FROM google_identities g JOIN users u ON u.id = g.user_id
WHERE g.google_sub = ? LIMIT 1 FOR UPDATE
SQL);
        $query->execute([$subject]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row
     * @return array{status:string,user_id?:int,session_stamp?:string}
     */
    private static function loginResult(array $row): array
    {
        $userId = (int) ($row['id'] ?? 0);
        $version = (int) ($row['auth_session_version'] ?? 0);
        if ($userId < 1 || $version < 1) {
            return ['status' => 'denied'];
        }
        if ($row['status'] === 'active' && $row['email_verified_at'] !== null
            && $row['approved_at'] !== null) {
            return [
                'status' => 'authenticated', 'user_id' => $userId,
                'session_stamp' => AuthenticationSessionVersion::stamp($version),
            ];
        }
        if ($row['status'] === 'pending' && $row['email_verified_at'] !== null) {
            return ['status' => 'pending', 'user_id' => $userId];
        }
        return ['status' => 'denied'];
    }

    /**
     * Link only identities for which the signed provider claims are
     * authoritative. Existing active accounts must already be eligible;
     * pending public self-service accounts may finish onboarding because the
     * provider has proved mailbox control. Manual/invited pending accounts and
     * disabled states retain their administrator-controlled lifecycle.
     *
     * @param array<string,mixed> $row
     * @return array{status:string,user_id?:int,session_stamp?:string}
     */
    private function linkAuthoritativeCollision(
        array $row, string $subject, string $email,
    ): array {
        $userId = (int) ($row['id'] ?? 0);
        $version = (int) ($row['auth_session_version'] ?? 0);
        $eligible = ($row['status'] ?? null) === 'active'
            && $row['email_verified_at'] !== null
            && $row['approved_at'] !== null;
        $selfServicePending = ($row['status'] ?? null) === 'pending'
            && ($row['signup_source'] ?? null) === 'self_service';
        if ($userId < 1 || $version < 1 || (!$eligible && !$selfServicePending)) {
            return ['status' => 'denied'];
        }

        $this->insertIdentity($userId, $subject, $email);
        $version++;
        $update = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET email_verified_at = CASE
        WHEN status = 'pending' AND signup_source = 'self_service'
        THEN COALESCE(email_verified_at, UTC_TIMESTAMP(6)) ELSE email_verified_at END,
    approved_at = CASE WHEN status = 'pending' AND signup_source = 'self_service'
        THEN COALESCE(approved_at, UTC_TIMESTAMP(6)) ELSE approved_at END,
    status = CASE WHEN status = 'pending' AND signup_source = 'self_service'
        THEN 'active' ELSE status END,
    auth_session_version = ?, updated_at = UTC_TIMESTAMP(6)
WHERE id = ?
SQL);
        $update->execute([$version, $userId]);
        if ($update->rowCount() !== 1) {
            throw new \RuntimeException('Google collision link revocation failed.');
        }
        return [
            'status' => 'linked', 'user_id' => $userId,
            'session_stamp' => AuthenticationSessionVersion::stamp($version),
        ];
    }

    private function insertIdentity(int $userId, string $subject, string $email): void
    {
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO google_identities (user_id, google_sub, google_email, linked_at)
VALUES (?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $insert->execute([$userId, $subject, strtolower($email)]);
    }

    private static function assertIdentity(string $subject, string $email): void
    {
        if (!self::validSubject($subject) || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[^\x21-\x7E]/', $email) === 1) {
            throw new InvalidArgumentException('Invalid Google identity.');
        }
    }

    private static function validSubject(string $subject): bool
    {
        return $subject !== '' && strlen($subject) <= 255
            && preg_match('/^[\x21-\x7E]+$/D', $subject) === 1;
    }
}
