<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use RuntimeException;

/**
 * Uses PHP-managed password algorithms, per-password random salts,
 * and timing-safe password_verify(). Never stores reversible credentials.
 */
final class PasswordHasher
{
    public function hash(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hashing unavailable.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
