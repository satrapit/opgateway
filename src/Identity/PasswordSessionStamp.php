<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;

/**
 * An opaque fingerprint of the *stored salted password hash*.
 *
 * No plaintext password or database hash is included in PHP session storage.
 * A password reset generates a new salted hash, changing this marker and
 * invalidating older authenticated browser sessions on their next request.
 */
final class PasswordSessionStamp
{
    public static function fromHash(string $passwordHash): string
    {
        if ($passwordHash === '' || strlen($passwordHash) > 255) {
            throw new InvalidArgumentException('Invalid password hash.');
        }
        return hash('sha256', "opgateway/password-session/v1\0" . $passwordHash);
    }
}
