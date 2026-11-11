<?php

declare(strict_types=1);

namespace OPGateway\Google;

use InvalidArgumentException;

/**
 * Verify Google-signed RS256 ID tokens using PEM certificates obtained over
 * verified TLS from the fixed Google endpoint. Never use JWT header URLs.
 */
final class GoogleOidcIdTokenVerifier
{
    private const RECENT_AUTHENTICATION_SECONDS = 300;

    /**
     * @param array<string,string> $certificates Fixed-URL Google kid=>PEM data.
     * @return array{sub:string,email:string,email_verified:bool}|null
     */
    public function verify(
        string $token, string $clientId, string $expectedNonce,
        array $certificates, bool $requireRecentAuthentication = false,
    ): ?array {
        if (strlen($token) > 16000 || !PdoGoogleOAuthSettings::validClientId($clientId)
            || preg_match('/^[0-9a-f]{64}$/D', $expectedNonce) !== 1) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerPart, $claimsPart, $signaturePart] = $parts;
        $headerJson = self::decodeSegment($headerPart);
        $claimsJson = self::decodeSegment($claimsPart);
        $signature = self::decodeSegment($signaturePart);
        if ($headerJson === null || $claimsJson === null || $signature === null) {
            return null;
        }
        try {
            $header = json_decode($headerJson, true, 32, JSON_THROW_ON_ERROR);
            $claims = json_decode($claimsJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($header) || !is_array($claims)
            || ($header['alg'] ?? null) !== 'RS256'
            || !is_string($header['kid'] ?? null)
            || strlen($header['kid']) > 128
            || !isset($certificates[$header['kid']])
            || !is_string($certificates[$header['kid']])) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certificates[$header['kid']]);
        if ($publicKey === false || openssl_verify(
            $headerPart . '.' . $claimsPart, $signature,
            $publicKey, OPENSSL_ALGO_SHA256,
        ) !== 1) {
            return null;
        }

        $now = time();
        if (!in_array($claims['iss'] ?? null, [
                'https://accounts.google.com', 'accounts.google.com',
            ], true)
            || ($claims['aud'] ?? null) !== $clientId
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60
            || $claims['iat'] < $now - 7200
            || $claims['exp'] - $claims['iat'] > 7200
            || !is_string($claims['nonce'] ?? null)
            || !hash_equals($expectedNonce, $claims['nonce'])
            || ($claims['email_verified'] ?? null) !== true
            || !is_string($claims['email'] ?? null)
            || strlen($claims['email']) > 254
            || filter_var($claims['email'], FILTER_VALIDATE_EMAIL) === false
            || !is_string($claims['sub'] ?? null)
            || $claims['sub'] === '' || strlen($claims['sub']) > 255
            || preg_match('/^[\\x21-\\x7E]+$/D', $claims['sub']) !== 1) {
            return null;
        }
        if ($requireRecentAuthentication
            && (!is_int($claims['auth_time'] ?? null)
                || $claims['auth_time'] > $now + 60
                || $claims['auth_time'] < $now - self::RECENT_AUTHENTICATION_SECONDS)) {
            return null;
        }
        return [
            'sub' => $claims['sub'],
            'email' => strtolower($claims['email']),
            'email_verified' => true,
        ];
    }

    private static function decodeSegment(string $data): ?string
    {
        if ($data === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $data) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($data, '-_', '+/')
            . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        return is_string($raw) ? $raw : null;
    }
}
