<?php

declare(strict_types=1);

namespace OPGateway\Security;

/**
 * Opaque 256-bit API bearer credential.
 *
 * Format: opg_<16 lowercase hex characters>.<64 lowercase hex characters>
 * The public prefix supports indexed lookup; the secret is never persisted.
 * SHA-256 is appropriate here only because secrets have 256-bit CSPRNG entropy.
 */
final class ApiKey
{
    private const PATTERN = '/^opg_([0-9a-f]{16})\.([0-9a-f]{64})$/D';

    /** @return array{key:string,prefix:string,digest:string} */
    public static function issue(): array
    {
        $prefix = 'opg_' . bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        return [
            'key' => $prefix . '.' . $secret,
            'prefix' => $prefix,
            'digest' => hash('sha256', $secret),
        ];
    }

    /** @return array{prefix:string,digest:string}|null */
    public static function parse(string $key): ?array
    {
        if (preg_match(self::PATTERN, $key, $parts) !== 1) {
            return null;
        }
        return [
            'prefix' => 'opg_' . $parts[1],
            'digest' => hash('sha256', $parts[2]),
        ];
    }
}
