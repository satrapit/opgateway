<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/**
 * Out-of-band one-time setup secret validation.
 *
 * Both the supplied token and configured SHA-256 digest are secrets.
 * Never log, echo, or persist the plaintext value. Generate 32 random bytes
 * using a trusted cryptographic source when the operator installer exists.
 */
final class BootstrapSecret
{
    public static function valid(string $submitted, string $expectedDigest): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $submitted) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $expectedDigest) !== 1) {
            return false;
        }

        return hash_equals($expectedDigest, hash('sha256', hex2bin($submitted)));
    }
}
