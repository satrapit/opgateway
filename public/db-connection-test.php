<?php

declare(strict_types=1);

/**
 * TEMPORARY operator-only database connection test for shared cPanel.
 *
 * This file is intentionally NOT deployed by .cpanel.yml.
 * For one controlled test only, copy it from the private checkout to
 * Document Root, enable DB_CONNECTION_TEST_ENABLED and exact allowed IP
 * in the private .env, request it over HTTPS, then disable and DELETE
 * the public copy. No SQL changes, secrets or raw errors are emitted.
 */
use OPGateway\Config\Environment;
use OPGateway\Database\PdoConnectionFactory;
use OPGateway\Database\MysqliConnectionProbe;
use OPGateway\Observability\DatabaseFailure;
use PDO;
use PDOException;

header('Cache-Control: no-store, private');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex, nofollow, noarchive');

$deny = static function (): never {
    http_response_code(404);
    echo '{"error":"not_found"}';
    exit;
};

// This is a temporary, manual File Manager copy, not a deployed route.
// Resolve private source exactly as public/index.php does.
$localRoot = dirname(__DIR__);
$root = is_file($localRoot . '/src/autoload.php')
    ? $localRoot
    : '/home/arsamnet/repositories/opgateway';

try {
    if (!is_file($root . '/src/autoload.php')) {
        $deny();
    }
    require_once $root . '/src/autoload.php';
    $config = Environment::load($root . '/.env');
} catch (Throwable) {
    $deny();
}

$source = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$allowed = (string) ($config['DB_CONNECTION_TEST_ALLOWED_IP'] ?? '');
$sourcePacked = @inet_pton($source);
$allowedPacked = @inet_pton($allowed);
if (($config['DB_CONNECTION_TEST_ENABLED'] ?? 'false') !== 'true'
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || ($_SERVER['HTTPS'] ?? '') !== 'on'
    || !is_string($sourcePacked)
    || !is_string($allowedPacked)
    || !hash_equals($allowedPacked, $sourcePacked)) {
    $deny();
}

$requestId = bin2hex(random_bytes(16));
header('X-Request-ID: ' . $requestId);

$pdoResult = ['status' => 'failed', 'reason' => 'unclassified'];
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    $pdoResult = ['status' => 'failed', 'reason' => 'driver_unavailable'];
} else {
    try {
        $pdo = PdoConnectionFactory::open($config);
        $statement = $pdo->query('SELECT 1');
        if ($statement !== false && (int) $statement->fetchColumn() === 1) {
            $pdoResult = ['status' => 'connected'];
        }
    } catch (PDOException $exception) {
        // Return only the reviewed, finite classification. Never return
        // getMessage(), exception trace, SQL, host or credentials.
        $diagnostic = DatabaseFailure::classify($exception);
        $pdoResult = [
            'status' => 'failed',
            'reason' => $diagnostic['reason'],
            'mysql_errno' => $diagnostic['mysql_errno'],
            'sqlstate_class' => $diagnostic['sqlstate_class'],
        ];
    } catch (Throwable) {
        $pdoResult = ['status' => 'failed', 'reason' => 'configuration_or_runtime_error'];
    } finally {
        unset($statement, $pdo);
    }
}

// The native MySQLi probe executes only SELECT 1 using the same private
// credentials; it never mutates database content.
try {
    $mysqliOutcome = MysqliConnectionProbe::inspect($config);
} catch (Throwable) {
    $mysqliOutcome = 'unclassified';
}

$mysqliResult = $mysqliOutcome === 'connected'
    ? ['status' => 'connected']
    : ['status' => 'failed', 'reason' => $mysqliOutcome];

$ok = $pdoResult['status'] === 'connected'
    && $mysqliResult['status'] === 'connected';
http_response_code($ok ? 200 : 503);
echo json_encode([
    'request_id' => $requestId,
    'pdo' => $pdoResult,
    'mysqli' => $mysqliResult,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
