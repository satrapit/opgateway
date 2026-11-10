<?php

declare(strict_types=1);

namespace OPGateway\Security;

use RuntimeException;

/**
 * One application encryption secret for stored SMTP/provider credentials and
 * domain-separated HMAC rate limits. Lives outside the public document root.
 *
 * The file is created once with an exclusive write and private permissions,
 * never saved in MySQL, .env, or Git. Back up this file separately: losing it
 * makes previously stored ciphertext unrecoverable.
 */
final class RuntimeSecrets
{
    public static function key(string $root): string
    {
        $dir = $root . '/storage';
        $path = $dir . '/app-secret.key';
        if (!is_dir($dir) || is_link($dir)) {
            throw new RuntimeException('Private application storage is unavailable.');
        }
        if (!file_exists($path) && !is_link($path)) {
            $handle = @fopen($path, 'x');
            if (is_resource($handle)) {
                try {
                    if (!@chmod($path, 0600)) {
                        throw new RuntimeException('Unable to secure application secret.');
                    }
                    $value = bin2hex(random_bytes(32)) . "\n";
                    if (fwrite($handle, $value) !== strlen($value) || !fflush($handle)) {
                        throw new RuntimeException('Unable to initialize application secret.');
                    }
                } finally {
                    fclose($handle);
                }
            } elseif (!is_file($path)) {
                throw new RuntimeException('Unable to initialize application secret.');
            }
        }
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Private application secret is unavailable.');
        }
        $mode = fileperms($path);
        if ($mode === false || ($mode & 0077) !== 0) {
            throw new RuntimeException('Application secret file permissions are unsafe.');
        }
        $value = trim((string) file_get_contents($path));
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException('Private application secret is invalid.');
        }
        return $value;
    }

    public static function limiterKey(string $root): string
    {
        $binary = hex2bin(self::key($root));
        if (!is_string($binary)) {
            throw new RuntimeException('Private application secret is invalid.');
        }
        return hash_hkdf('sha256', $binary, 32, 'opgateway-rate-limit-v1');
    }
}
