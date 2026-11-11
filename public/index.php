<?php

declare(strict_types=1);

use OPGateway\Config\Environment;
use OPGateway\Http\AdministrativeWebEntry;
use OPGateway\Http\AccountWebEntry;
use OPGateway\Http\FoundationApplication;
use OPGateway\Http\GatewayHttpEntry;
use OPGateway\Http\BrowserGetPath;
use OPGateway\Observability\DiagnosticLog;

/**
 * cPanel split-root entrypoint; private source and secrets remain outside
 * Document Root. The initial admin is provisioned once in the database.
 */
$localRoot = dirname(__DIR__);
$applicationRoot = is_file($localRoot . '/src/autoload.php')
    ? $localRoot
    : '/home/arsamnet/repositories/opgateway';

// Correlate HTTP responses with metadata-only diagnostics.
$requestId = bin2hex(random_bytes(16));
try {
    if (!is_file($applicationRoot . '/src/autoload.php')) {
        throw new RuntimeException('Application bootstrap unavailable.');
    }

    require $applicationRoot . '/src/autoload.php';
    DiagnosticLog::initialize($applicationRoot . '/storage/logs/opgateway.jsonl', $requestId);
    $config = Environment::load($applicationRoot . '/.env');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    // Accept a terminal slash on supported GET browser pages without
    // changing POST endpoints or losing the query string for reset links.
    $path = BrowserGetPath::normalize($method, $path);

    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        $response = (new AdministrativeWebEntry($config))->handle($method, $path, $_SERVER);
    } elseif ($path === '/account' || str_starts_with($path, '/account/')) {
        $response = (new AccountWebEntry($config))->handle($method, $path, $_SERVER);
    } elseif ($path === '/v1' || str_starts_with($path, '/v1/')) {
        $response = (new GatewayHttpEntry($config))->handle($method, $path, $_SERVER, $requestId);
    } else {
        $result = (new FoundationApplication())->handle($method, $path);
        $response = [
            'status' => $result['status'],
            'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
        if (isset($result['location'])) {
            $response['location'] = $result['location'];
        }
    }
} catch (Throwable $exception) {
    if (class_exists(DiagnosticLog::class)) {
        DiagnosticLog::record('http.unhandled_exception', 503, $exception);
    } else {
        error_log('OPGateway bootstrap unavailable.');
    }
    // Do not reflect filesystem paths, credentials, SQL or exception messages.
    $response = [
        'status' => 503,
        'content_type' => 'application/json; charset=utf-8',
        'body' => '{"error":"service_unavailable"}',
    ];
}

http_response_code($response['status']);
if (isset($response['location'])) {
    // Google authorization is the sole external redirect; its URL is built
    // from a fixed Google endpoint and validated client data by the server.
    $location = $response['location'];
    if (preg_match('~^/(?:account|admin)(?:/|$)~D', $location) !== 1
        && !str_starts_with($location,
            'https://accounts.google.com/o/oauth2/v2/auth?')) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"service_unavailable"}';
        exit;
    }
    header('Location: ' . $location, true, $response['status']);
}
header('Content-Type: ' . $response['content_type']);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
// Preserve browser Origin on same-origin form POST while suppressing
// cross-origin referrer disclosures.
header('Referrer-Policy: same-origin');
header('Content-Security-Policy: ' . ($response['csp']
    ?? "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"));
header('X-Request-ID: ' . $requestId);
echo $response['body'];
