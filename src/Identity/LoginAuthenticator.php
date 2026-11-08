<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/**
 * Password authentication without observable account-state distinctions.
 *
 * HTTP callers must use GuardedLoginService for persistent throttling
 * and a secure transport, server-side session CSRF and generic errors.
 */
final readonly class LoginAuthenticator
{
    // A valid, synthetic bcrypt hash ensures absent accounts still perform
    // a password verification operation instead of immediately returning.
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(
        private LoginAccountRepository $accounts,
        private PasswordHasher $hasher,
    ) {
    }

    /** Return the authenticated user id or null. Never expose why denied. */
    public function authenticate(string $email, string $password): ?int
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[^\x21-\x7E]/', $email) === 1
            || strlen($password) > 1024 || str_contains($password, "\0")) {
            return null;
        }

        $account = $this->accounts->findEligibleByEmail($email);
        $hash = $account['password_hash'] ?? self::DUMMY_HASH;
        $valid = $this->hasher->verify($password, $hash);

        if (!$valid || $account === null || $account['id'] < 1) {
            return null;
        }

        return $account['id'];
    }
}
