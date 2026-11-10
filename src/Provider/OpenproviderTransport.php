<?php

declare(strict_types=1);

namespace OPGateway\Provider;

use RuntimeException;

/**
 * HTTPS transport to exactly one pinned upstream authority.
 *
 * Redirects, custom destinations, unbounded response streams and TLS
 * bypasses are all prohibited. Credentials are sent only to /auth/login;
 * caller tokens and provider tokens never appear in logs.
 */
class OpenproviderTransport
{
    private const BASE_URL = 'https://api.openprovider.eu/v1';

    /** @return array{status:int,body:string} */
    public function request(string $method, string $path, string $query, ?string $body, ?string $bearer): array
    {
        if (($path !== '/auth/login' && !OpenproviderRoutePolicy::allowed($method, '/v1' . $path, true))
            || ($path === '/auth/login' && ($method !== 'POST' || $bearer !== null))
            || !OpenproviderRoutePolicy::validQuery($query)
            || ($body !== null && strlen($body) > 16384)) {
            throw new RuntimeException('Upstream request not allowlisted.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('HTTPS transport unavailable.');
        }

        $url = self::BASE_URL . $path . ($query === '' ? '' : '?' . $query);
        $buffer = '';
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('HTTPS transport unavailable.');
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($bearer !== null) {
            if ($bearer === '' || strlen($bearer) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $bearer)) {
                throw new RuntimeException('Invalid upstream token.');
            }
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => 'identity',
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$buffer): int {
                    if (strlen($buffer) + strlen($chunk) > 524288) {
                        return 0;
                    }
                    $buffer .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            if ($ok === false || $status < 100 || $status >= 400 && $status >= 500
                || $status >= 300 && $status < 400
                || !preg_match('~^(?:application/(?:[\w.+-]*\+)?json)(?:\s*;|$)~i', $type)
                || !json_validate($buffer)) {
                throw new RuntimeException('Unexpected upstream response.');
            }
            return ['status' => $status, 'body' => $buffer];
        } finally {
            curl_close($handle);
        }
    }

    public function login(string $username, string $password): string
    {
        $body = json_encode([
            'username' => $username, 'password' => $password, 'ip' => '0.0.0.0',
        ], JSON_THROW_ON_ERROR);
        $result = $this->request('POST', '/auth/login', '', $body, null);
        if ($result['status'] !== 200) {
            throw new RuntimeException('Upstream authentication failed.');
        }
        $decoded = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);
        $token = $decoded['data']['token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096
            || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            throw new RuntimeException('Invalid upstream authentication response.');
        }
        return $token;
    }
}
