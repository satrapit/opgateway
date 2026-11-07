<?php

declare(strict_types=1);

use OPGateway\Config\Environment;
use OPGateway\Http\FoundationApplication;
use OPGateway\Http\SetupInstaller;

/**
 * Split-root cPanel bootstrap. Application source and private .env remain
 * outside Document Root; setup is disabled by default.
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
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if ($path === '/setup/administrator') {
        $response = (new SetupInstaller($config))->handle($method, $_SERVER);
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
} catch (Throwable) {
    // Never expose configuration, filesystem, SQL or request secrets.
    $response = ['status' => 503, 'content_type' => 'application/json; charset=utf-8',
        'body' => '{"error":"service_unavailable"}'];
}

http_response_code($response['status']);
header('Content-Type: ' . $response['content_type']);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Request-ID: ' . bin2hex(random_bytes(16)));
echo $response['body'];
