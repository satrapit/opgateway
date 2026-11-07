<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use OPGateway\Config\Environment;
use OPGateway\Http\FoundationApplication;
use OPGateway\Security\IpAddress;
use OPGateway\Logging\SafeRequestMetadata;

$cases = [
    'health response' => (new FoundationApplication([]))->handle('GET', '/health', '127.0.0.1') === ['status' => 200, 'body' => ['status' => 'ok']],
    'upstream blocked by default' => (new FoundationApplication([]))->handle('GET', '/v1/domains', '127.0.0.1')['status'] === 503,
    'upstream blocked even with flag enabled' => (new FoundationApplication(['GATEWAY_PROXY_ENABLED'=>'true']))->handle('POST', '/v1/domains', '127.0.0.1')['status'] === 503,
    'unknown routes rejected' => (new FoundationApplication([]))->handle('GET', '/admin', '127.0.0.1')['status'] === 404,
    'IPv4 allow' => IpAddress::allowed('192.0.2.7', ['192.0.2.7']),
    'IPv4 deny' => !IpAddress::allowed('192.0.2.8', ['192.0.2.7']),
    'IPv6 allow' => IpAddress::allowed('2001:db8::1', ['2001:0db8::1']),
    'invalid IP deny' => !IpAddress::allowed('bad-ip', ['bad-ip']),
    'empty whitelist deny' => !IpAddress::allowed('192.0.2.7', []),
    'safe metadata keys' => array_keys(SafeRequestMetadata::create('id', 'GET', 200, 12)) === ['request_id', 'method', 'status', 'elapsed_ms'],
];
$passed = 0;
foreach ($cases as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . "\n";
    $passed += (int) $ok;
}
echo "$passed/" . count($cases) . " smoke checks passed\n";
exit($passed === count($cases) ? 0 : 1);
