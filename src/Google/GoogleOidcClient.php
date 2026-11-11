<?php

declare(strict_types=1);

namespace OPGateway\Google;

use RuntimeException;

/** Network-only OIDC authorization-code exchange with fixed Google hosts. */
final readonly class GoogleOidcClient implements GoogleOidcProvider
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    /**
     * @return array{sub:string,email:string,email_verified:bool}
     * @throws RuntimeException On any provider, TLS or token validation failure.
     */
    public function exchange(
        string $code, string $clientId, string $clientSecret,
        string $origin, string $verifier, string $nonce,
        bool $requireRecentAuthentication,
    ): array {
        if ($code === '' || strlen($code) > 2048 || str_contains($code, "\0")
            || $clientSecret === '' || strlen($clientSecret) > 4096
            || preg_match('~^https://[a-z0-9.-]+$~D', $origin) !== 1
            || preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $verifier) !== 1) {
            throw new RuntimeException('Google authorization invalid.');
        }
        $body = http_build_query([
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $origin . '/account/google/callback',
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ], '', '&', PHP_QUERY_RFC3986);
        $tokenData = self::request(self::TOKEN_URL, $body, 16384);
        $idToken = $tokenData['id_token'] ?? null;
        if (!is_string($idToken) || strlen($idToken) > 16000) {
            throw new RuntimeException('Google identity response invalid.');
        }
        $certificates = self::request(self::CERTS_URL, null, 131072);
        $identity = (new GoogleOidcIdTokenVerifier())->verify(
            $idToken, $clientId, $nonce, $certificates,
            $requireRecentAuthentication,
        );
        if ($identity === null) {
            throw new RuntimeException('Google identity verification failed.');
        }
        // Access/refresh tokens from the provider response are never persisted.
        return $identity;
    }

    /** @return array<string,mixed> */
    private static function request(string $url, ?string $postBody, int $maxBytes): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Google connection unavailable.');
        }
        $body = '';
        try {
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_USERAGENT => 'OPGateway/GoogleOIDC',
                CURLOPT_HTTPHEADER => $postBody === null ? ['Accept: application/json']
                    : ['Accept: application/json',
                        'Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk)
                    use (&$body, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($postBody !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
            }
            $ok = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status !== 200 || $body === '') {
                throw new RuntimeException('Google request failed.');
            }
        } finally {
            curl_close($ch);
        }
        try {
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Google response invalid.');
        }
        if (!is_array($result)) {
            throw new RuntimeException('Google response invalid.');
        }
        return $result;
    }
}
