<?php

declare(strict_types=1);

use OPGateway\Config\Environment;
use OPGateway\Http\FoundationApplication;

require dirname(__DIR__) . '/src/autoload.php';

$root = dirname(__DIR__);
try {
    $config = Environment::load($root . '/.env');
    $application = new FoundationApplication($config);
    $result = $application->handle(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        $_SERVER['REMOTE_ADDR'] ?? ''
    );
} catch (Throwable $exception) {
    // Never expose exception details or credentials to HTTP clients.
    $result = ['status' => 503, 'body' => ['error' => 'service_unavailable']];
}
http_response_code($result['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Request-ID: ' . bin2hex(random_bytes(16)));
echo json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
