<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Database\Migrations\MigrationCatalog;
use OPGateway\Database\Migrations\SchemaReadiness;
use OPGateway\Database\PdoConnectionFactory;
use OPGateway\Provider\OpenproviderRoutePolicy;
use OPGateway\Provider\OpenproviderTransport;
use OPGateway\Provider\PdoProviderAccount;
use OPGateway\Security\ApiClientIpPolicy;
use OPGateway\Security\ApiCredentialService;
use OPGateway\Security\PdoApiCredentialStore;
use OPGateway\Security\PdoGatewayRateLimiter;
use OPGateway\Security\SealedSecret;
use OPGateway\Security\PdoSecurityEventRecorder;
use OPGateway\Observability\DiagnosticLog;
use PDO;
use Throwable;

/**
 * Explicitly opted-in and authenticated Openprovider-compatible API path.
 * Only the gateway-issued bearer from an approved, active client + its
 * exactly approved transport source IP can reach the fixed provider host.
 *
 * Writes, which may charge a reseller, require another independent flag.
 * We never expose an upstream bearer, password, or exception in responses.
 */
final readonly class GatewayHttpEntry
{
    /** @param array<string,string> $config */
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string,mixed> $server Transport-authenticated PHP metadata.
     * @return array{status:int,content_type:string,body:string}
     */
    public function handle(
        string $method, string $path, array $server, string $requestId = '',
    ): array
    {
        if (($this->config['GATEWAY_PROXY_ENABLED'] ?? '') !== 'true') {
            return self::error(503, 'gateway_not_configured');
        }
        if (($server['HTTPS'] ?? '') !== 'on'
            || ($this->config['ACCOUNT_KEYS_ENABLED'] ?? '') !== 'true') {
            return self::error(403, 'forbidden');
        }
        $origin = $this->config['APP_URL'] ?? '';
        if (preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~iD', $origin) !== 1
            || !hash_equals(strtolower(substr($origin, 8)),
                strtolower((string) ($server['HTTP_HOST'] ?? '')))) {
            return self::error(404, 'not_found');
        }
        if (!OpenproviderRoutePolicy::allowed(
            $method, $path, ($this->config['GATEWAY_PROXY_WRITE_ENABLED'] ?? '') === 'true',
        )) {
            return self::error(404, 'operation_not_available');
        }
        $query = (string) ($server['QUERY_STRING'] ?? '');
        if (!OpenproviderRoutePolicy::validQuery($query)
            || ($method !== 'GET' && $query !== '')) {
            return self::error(400, 'invalid_request');
        }
        $authorization = (string) (
            $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''
        );
        if (!preg_match('/^Bearer (opg_[0-9a-f]{16}\.[0-9a-f]{64})$/D', $authorization, $matches)) {
            return self::error(401, 'unauthorized');
        }
        $sourceIp = (string) ($server['REMOTE_ADDR'] ?? '');
        $packed = @inet_pton($sourceIp);
        if ($packed === false || !in_array(strlen($packed), [4, 16], true)) {
            return self::error(403, 'forbidden');
        }
        $secret = $this->config['LOGIN_RATE_LIMIT_KEY'] ?? '';
        if (preg_match('/^[0-9a-f]{64}$/iD', $secret) !== 1
            || !isset($this->config['APP_KEY'])) {
            return self::error(503, 'service_unavailable');
        }
        try {
            $vault = new SealedSecret($this->config['APP_KEY']);
            if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
                return self::error(503, 'service_unavailable');
            }
            $pdo = PdoConnectionFactory::open($this->config);
            if (!(new SchemaReadiness($pdo))->inspect(MigrationCatalog::all())['ready']) {
                return self::error(503, 'service_unavailable');
            }
            $authenticator = new ApiCredentialService(
                new PdoApiCredentialStore($pdo), new ApiClientIpPolicy($pdo),
            );
            $clientId = $authenticator->authenticate($matches[1], $sourceIp);
            if ($clientId === null) {
                return self::error(403, 'forbidden');
            }
            if (!(new PdoGatewayRateLimiter($pdo, hex2bin($secret)))->allow($clientId, $sourceIp)) {
                return self::error(429, 'too_many_requests');
            }
            if (preg_match('/^[a-f0-9]{32}$/D', $requestId) === 1) {
                (new PdoSecurityEventRecorder($pdo))->record('gateway_request_admitted', $requestId);
            }
            $provider = new PdoProviderAccount($pdo, $vault);
            $credentials = $provider->credentialsForClient($clientId);
            if ($credentials === null) {
                return self::error(409, 'provider_account_not_configured');
            }

            $body = null;
            if ($method !== 'GET') {
                $contentType = (string) ($server['CONTENT_TYPE'] ?? '');
                if (preg_match('~^application/json(?:\s*;\s*charset=utf-8)?$~iD', $contentType) !== 1) {
                    return self::error(415, 'unsupported_media_type');
                }
                $stream = fopen('php://input', 'rb');
                if ($stream === false) {
                    return self::error(400, 'invalid_request');
                }
                try {
                    $body = BoundedRequestBody::read(
                        $stream,
                        isset($server['CONTENT_LENGTH']) ? (string) $server['CONTENT_LENGTH'] : null,
                    );
                } finally {
                    fclose($stream);
                }
                if ($body === null) {
                    return self::error(413, 'payload_too_large');
                }
                if (!json_validate($body)) {
                    return self::error(400, 'invalid_json');
                }
            }
            $transport = new OpenproviderTransport();
            $ownerId = $credentials['owner_id'];
            $upstreamToken = $provider->getCachedToken($ownerId);
            if ($upstreamToken === null) {
                $upstreamToken = $transport->login(
                    $credentials['username'], $credentials['password'],
                );
                $provider->saveToken($ownerId, $upstreamToken);
            }

            // No automatic replay of writes on upstream auth failures.
            $result = $transport->request(
                $method, substr($path, 3), $query, $body, $upstreamToken,
            );
            if ($result['status'] === 401 || $result['status'] === 403) {
                $provider->clearToken($ownerId);
            }
            return [
                'status' => $result['status'],
                'content_type' => 'application/json; charset=utf-8',
                'body' => $result['body'],
            ];
        } catch (Throwable $exception) {
            DiagnosticLog::record('gateway.request_failed', 502, $exception);
            // Error context (including curl errors, SQL and tokens) never
            // enters HTTP output or persisted diagnostic metadata.
            return self::error(502, 'upstream_unavailable');
        }
    }

    /** @return array{status:int,content_type:string,body:string} */
    private static function error(int $status, string $reason): array
    {
        return [
            'status' => $status,
            'content_type' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => $reason], JSON_THROW_ON_ERROR),
        ];
    }
}
