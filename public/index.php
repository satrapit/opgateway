<?php

declare(strict_types=1);

use OPGateway\Config\Environment;
use OPGateway\Http\AdministrativeWebEntry;
use OPGateway\Http\FoundationApplication;
use OPGateway\Observability\DiagnosticLog;

/**
 * cPanel split-root entrypoint; private source and secrets remain outside
 * Document Root. Admin UI is default-disabled; initial admin is provisioned manually in DB.
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

    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        $response = (new AdministrativeWebEntry($config))->handle($method, $path, $_SERVER);
    } else {
        $result = (new FoundationApplication($config))->handle(
            $method, $path, $_SERVER['REMOTE_ADDR'] ?? '',
        );
        $response = [
            'status' => $result['status'],
            'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
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
header('Content-Type: ' . $response['content_type']);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
// Preserve browser Origin on same-origin form POST while suppressing
// cross-origin referrer disclosures.
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Request-ID: ' . $requestId);
echo $response['body'];
