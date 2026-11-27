<?php

declare(strict_types=1);

/**
 * Non-mutating HTTP acceptance probes for the existing opgateway.test site.
 * No credentials, tokens, SMTP calls, account creation, or state-changing POSTs.
 * Browser GET routes may create ephemeral PHP sessions/CAPTCHA challenges.
 */

const BASE_URL = 'https://opgateway.test';

if (!extension_loaded('curl')) {
    fwrite(STDERR, "FAIL: PHP curl extension is required.\n");
    exit(1);
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function request(string $path): array
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        throw new RuntimeException('Invalid relative path.');
    }

    $headers = [];
    $handle = curl_init(BASE_URL . $path);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize HTTP client.');
    }
    try {
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: text/html, application/json'],
            CURLOPT_USERAGENT => 'OPGateway-Local-SafeSmoke/1.0',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $headers[$name] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw new RuntimeException('HTTPS connection failed (check local site and trust store).');
        }
        return [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => $body,
        ];
    } finally {
        curl_close($handle);
    }
}

$cases = [
    ['name' => 'health JSON', 'path' => '/health', 'status' => 200,
        'body' => '"status":"ok"'],
    ['name' => 'account login and CSRF form', 'path' => '/account/login', 'status' => 200,
        'body' => 'name="_csrf"', 'html' => true],
    ['name' => 'admin login and CSRF form', 'path' => '/admin/login', 'status' => 200,
        'body' => 'name="_csrf"', 'html' => true],
    ['name' => 'registration form', 'path' => '/account/register', 'status' => 200,
        'body' => 'action="/account/register"', 'html' => true],
    ['name' => 'password recovery form (no email sent)', 'path' => '/account/forgot-password',
        'status' => 200, 'body' => 'action="/account/forgot-password"', 'html' => true],
    ['name' => 'account dashboard protected', 'path' => '/account', 'status' => 303,
        'location' => '/account/login'],
    ['name' => 'account profile protected', 'path' => '/account/profile', 'status' => 303,
        'location' => '/account/login'],
    ['name' => 'admin dashboard protected', 'path' => '/admin', 'status' => 303,
        'location' => '/admin/login'],
    ['name' => 'SMTP admin settings protected', 'path' => '/admin/settings/smtp',
        'status' => 303, 'location' => '/admin/login'],
    ['name' => 'unknown route rejected', 'path' => '/opgateway-safe-smoke-no-such-route',
        'status' => 404],
];

$pass = 0;
foreach ($cases as $case) {
    $ok = false;
    try {
        $response = request($case['path']);
        $headers = $response['headers'];
        $ok = $response['status'] === $case['status'];
        $ok = $ok && ($headers['cache-control'] ?? '') !== ''
            && str_contains(strtolower($headers['cache-control']), 'no-store');
        $ok = $ok && strtolower($headers['x-content-type-options'] ?? '') === 'nosniff';
        $ok = $ok && strtolower($headers['x-frame-options'] ?? '') === 'deny';
        if (isset($case['location'])) {
            $ok = $ok && ($headers['location'] ?? '') === $case['location'];
        }
        if (isset($case['body'])) {
            $ok = $ok && str_contains($response['body'], $case['body']);
        }
        if ($case['html'] ?? false) {
            $csp = $headers['content-security-policy'] ?? '';
            $ok = $ok && str_contains($csp, "script-src 'self'")
                && str_contains($csp, "form-action 'self'")
                && !str_contains($csp, 'unsafe-eval');
        }
        if (!$ok) {
            fwrite(STDERR, "FAIL {$case['name']} (HTTP {$response['status']}; unexpected status, headers, or content)\n");
        }
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL {$case['name']} (request or trust validation failed)\n");
    }
    if ($ok) {
        $pass++;
        echo "PASS {$case['name']}\n";
    }
}

echo "{$pass}/" . count($cases) . " local HTTPS checks passed\n";
exit($pass === count($cases) ? 0 : 1);
