<?php

declare(strict_types=1);

use OPGateway\Config\Environment;
use OPGateway\Http\FoundationApplication;

/**
 * Resolve the private application directory for both local development and
 * cPanel's split source/public deployment. Never use untrusted request input.
 *
 * The production path is a host-specific deployment assumption: update it
 * deliberately when installing the project under a different cPanel user.
 */
$localRoot = dirname(__DIR__);
$applicationRoot = is_file($localRoot . '/src/autoload.php')
    ? $localRoot
    : '/home/arsamnet/repositories/opgateway';

try {
    if (!is_file($applicationRoot . '/src/autoload.php')) {
        throw new RuntimeException('Application bootstrap unavailable.');
    }

    require $applicationRoot . '/src/autoload.php';

    $config = Environment::load($applicationRoot . '/.env');
    $application = new FoundationApplication($config);
    $result = $application->handle(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        $_SERVER['REMOTE_ADDR'] ?? ''
    );
} catch (Throwable $exception) {
    // Do not reflect filesystem paths, configuration values or stack traces.
    $result = ['status' => 503, 'body' => ['error' => 'service_unavailable']];
}

http_response_code($result['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Request-ID: ' . bin2hex(random_bytes(16)));
echo json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
