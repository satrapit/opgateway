<?php

declare(strict_types=1);

namespace OPGateway\Clients;

use OPGateway\Provider\PdoProviderAccount;
use OPGateway\Security\CredentialActionLimiter;
use OPGateway\Security\SecurityEventRecorder;
use PDO;
use PDOException;
use Throwable;

/**
 * CSRF/session-authenticated owner changes are rate limited and audited.
 * Client/IP grants remain disabled until the existing admin workflow
 * explicitly approves them; public signup never grants API privilege.
 */
final readonly class OwnerIntegrationService
{
    public function __construct(
        private PDO $pdo,
        private PdoProviderAccount $provider,
        private ClientManagementService $clients,
        private CredentialActionLimiter $limiter,
        private SecurityEventRecorder $events,
    ) {
    }

    /** @return array{configured:bool,username:string} */
    public function summary(int $ownerId): array
    {
        return $this->provider->summary($ownerId);
    }

    /** @return 'ok'|'denied'|'limited' */
    public function saveAccount(
        int $ownerId, string $username, string $password, string $sourceIp, string $requestId,
    ): string {
        return $this->mutate($ownerId, $sourceIp, $requestId,
            fn (): bool => $this->provider->save($ownerId, $username, $password),
            'provider_credentials_saved');
    }

    /** @return 'ok'|'denied'|'limited' */
    public function createClient(
        int $ownerId, string $label, string $sourceIp, string $requestId,
    ): string {
        return $this->mutate($ownerId, $sourceIp, $requestId,
            fn (): bool => $this->clients->create($ownerId, $label) !== null,
            'owner_client_requested');
    }

    /** @return 'ok'|'denied'|'limited' */
    public function requestIp(
        int $ownerId, int $clientId, string $address, string $sourceIp, string $requestId,
    ): string {
        return $this->mutate($ownerId, $sourceIp, $requestId,
            fn (): bool => $this->clients->requestIp($ownerId, $clientId, $address),
            'owner_ip_requested');
    }

    /** @return 'ok'|'denied'|'limited' */
    private function mutate(
        int $ownerId, string $sourceIp, string $requestId,
        callable $operation, string $eventCode,
    ): string {
        if ($ownerId < 1) {
            return 'denied';
        }
        if (!$this->limiter->allow($ownerId, $sourceIp)) {
            $this->events->record('owner_management_throttled', $requestId);
            return 'limited';
        }
        $this->pdo->beginTransaction();
        try {
            try {
                $ok = $operation();
            } catch (PDOException $exception) {
                // Duplicate client IP should not cause an internal 500 error.
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
                $ok = false;
            }
            $this->events->record($ok ? $eventCode : 'owner_management_denied', $requestId);
            $this->pdo->commit();
            return $ok ? 'ok' : 'denied';
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
