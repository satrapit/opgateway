<?php

declare(strict_types=1);

namespace OPGateway\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Versioned AES-256-GCM secret envelopes tied to their intended record.
 *
 * Never use password_hash for upstream/SMTP credentials that must be
 * decrypted to perform an authorized server-to-server operation. The
 * application key comes from private storage/app-secret.key, not .env.
 * It must be 32 random bytes encoded as 64 hexadecimal characters.
 * Store ciphertext in its designated private database tables, not Document Root.
 */
final readonly class SealedSecret
{
    private string $key;

    public function __construct(string $hexKey)
    {
        if (preg_match('/^[0-9a-f]{64}$/iD', $hexKey) !== 1) {
            throw new InvalidArgumentException('Application encryption key must contain 32 random bytes.');
        }
        $key = hex2bin($hexKey);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new InvalidArgumentException('Invalid application encryption key.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext, string $context): string
    {
        if ($plaintext === '' || strlen($plaintext) > 4096 || !self::validContext($context)) {
            throw new InvalidArgumentException('Invalid secret or context.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
            $nonce, $tag, $context, 16,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Secret encryption unavailable.');
        }
        return 'v1:' . base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $envelope, string $context): string
    {
        if (!self::validContext($context) || !str_starts_with($envelope, 'v1:')
            || strlen($envelope) > 6000) {
            throw new RuntimeException('Invalid encrypted secret envelope.');
        }
        $binary = base64_decode(substr($envelope, 3), true);
        if (!is_string($binary) || strlen($binary) <= 28) {
            throw new RuntimeException('Invalid encrypted secret envelope.');
        }
        $plaintext = openssl_decrypt(
            substr($binary, 28), 'aes-256-gcm', $this->key,
            OPENSSL_RAW_DATA, substr($binary, 0, 12),
            substr($binary, 12, 16), $context,
        );
        if (!is_string($plaintext) || $plaintext === '') {
            throw new RuntimeException('Unable to authenticate secret.');
        }
        return $plaintext;
    }

    private static function validContext(string $context): bool
    {
        return preg_match('/^(?:provider:[1-9][0-9]*|smtp:1|google:oauth)$/D', $context) === 1;
    }
}
