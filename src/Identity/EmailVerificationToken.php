<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;

/**
 * Cryptographically secure email verification token utilities.
 *
 * The raw token belongs only in the eventual email delivery transport;
 * never save it in a database, log, or analytics event.
 */
final class EmailVerificationToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            throw new InvalidArgumentException('Invalid verification token.');
        }

        return hash('sha256', strtolower($token));
    }

    public static function ttlSeconds(): int
    {
        return 1800;
    }
}
