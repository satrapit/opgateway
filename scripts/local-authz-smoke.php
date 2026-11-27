<?php

declare(strict_types=1);

/**
 * Anonymous authorization checks against https://opgateway.test.
 *
 * No credentials, reusable cookies, verification tokens, or real entity IDs.
 * Every POST has an invalid CSRF token and an empty browser session.
 * These requests are expected to be rejected BEFORE any business mutation.
 * Server-side ephemeral PHP sessions and metadata logs may be created.
 */

const BASE_URL = 'https://opgateway.test';

if (!extension_loaded('curl')) {
    fwrite(STDERR, "FAIL: PHP curl extension is required.\n");
    exit(1);
}

/** @return array{status:int,headers:array<string,list<string>>} */
function probe(string $method, string $path): array
{
    if (!in_array($method, ['GET', 'POST'], true)
        || !str_starts_with($path, '/')
        || str_starts_with($path, '//')) {
        throw new RuntimeException('Invalid probe request.');
    }

    $headers = [];
    $curl = curl_init(BASE_URL . $path);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize curl.');
    }

    try {
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'OPGateway-Local-AnonymousAuthz/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html, application/json'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $headers[$name][] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);

        if ($method === 'POST') {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '_csrf=invalid',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json, text/html',
                    'Content-Type: application/x-www-form-urlencoded',
                ],
            ]);
        }

        $response = curl_exec($curl);
        if (!is_string($response)) {
            throw new RuntimeException('HTTPS request failed or certificate untrusted.');
        }

        return [
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
        ];
    } finally {
        curl_close($curl);
    }
}

/** @param array<string,list<string>> $headers */
function headerValue(array $headers, string $name): string
{
    return $headers[strtolower($name)][0] ?? '';
}

$cases = [
    ['admin review POST denied', 'POST', '/admin/review', 401, null],
    ['admin provision POST denied', 'POST', '/admin/provision', 401, null],
    ['admin client creation denied', 'POST', '/admin/clients/create', 303, '/admin/login'],
    ['admin IP request denied', 'POST', '/admin/ips/request', 303, '/admin/login'],
    ['admin user invitation denied', 'POST', '/admin/users/invite', 303, '/admin/login'],
    ['admin security settings denied', 'POST', '/admin/settings/security', 303, '/admin/login'],
    ['admin Google settings denied', 'POST', '/admin/settings/login/google', 303, '/admin/login'],
    ['admin SMTP settings denied', 'POST', '/admin/settings/smtp', 303, '/admin/login'],
    ['Gmail action toggle denied', 'POST', '/admin/settings/smtp/gmail-actions', 303, '/admin/login'],
    ['account profile edit denied', 'POST', '/account/profile', 303, '/account/login'],
    ['account key issuance denied', 'POST', '/account/keys/issue', 401, null],
    ['account key revocation denied', 'POST', '/account/keys/revoke', 401, null],
    ['account provider change denied', 'POST', '/account/provider/save', 401, null],
    ['account client creation denied', 'POST', '/account/clients/create', 401, null],
    ['account IP request denied', 'POST', '/account/ips/request', 401, null],
    ['account logout denied', 'POST', '/account/logout', 401, null],
];

$passed = 0;
foreach ($cases as [$name, $method, $path, $expectedStatus, $expectedLocation]) {
    $ok = false;
    try {
        $response = probe($method, $path);
        $headers = $response['headers'];
        $ok = $response['status'] === $expectedStatus
            && str_contains(strtolower(headerValue($headers, 'cache-control')), 'no-store')
            && strtolower(headerValue($headers, 'x-content-type-options')) === 'nosniff'
            && strtolower(headerValue($headers, 'x-frame-options')) === 'deny'
            && preg_match('/^[0-9a-f]{32}$/D', headerValue($headers, 'x-request-id')) === 1;
        if ($expectedLocation !== null) {
            $ok = $ok && headerValue($headers, 'location') === $expectedLocation;
        } else {
            $ok = $ok && headerValue($headers, 'location') === '';
        }
        if (!$ok) {
            fprintf(STDERR, "FAIL %s (HTTP %d; unexpected rejection or security headers)\n",
                $name, $response['status']);
        }
    } catch (Throwable) {
        fwrite(STDERR, "FAIL {$name} (HTTPS request failed)\n");
    }
    if ($ok) {
        ++$passed;
        echo "PASS {$name}\n";
    }
}

foreach (['/admin/login', '/account/login'] as $path) {
    $ok = false;
    $name = $path . ' anonymous session cookie protected';
    try {
        $response = probe('GET', $path);
        $cookies = $response['headers']['set-cookie'] ?? [];
        foreach ($cookies as $cookie) {
            if (preg_match('/^OPGATEWAYSESSID=/', $cookie) !== 1) {
                continue;
            }
            $ok = preg_match('/(?:^|;)\s*Secure(?:;|$)/i', $cookie) === 1
                && preg_match('/(?:^|;)\s*HttpOnly(?:;|$)/i', $cookie) === 1
                && preg_match('/(?:^|;)\s*SameSite=Lax(?:;|$)/i', $cookie) === 1;
        }
        $ok = $ok && $response['status'] === 200;
        if (!$ok) {
            fwrite(STDERR, "FAIL {$name} (HTTP status or session cookie attributes)\n");
        }
    } catch (Throwable) {
        fwrite(STDERR, "FAIL {$name} (HTTPS request failed)\n");
    }
    if ($ok) {
        ++$passed;
        echo "PASS {$name}\n";
    }
}

$total = count($cases) + 2;
echo "{$passed}/{$total} anonymous authorization checks passed\n";
exit($passed === $total ? 0 : 1);
