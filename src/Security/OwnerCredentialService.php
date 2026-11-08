<?php

declare(strict_types=1);

namespace OPGateway\Security;

use PDO;
use Throwable;

/**
 * Owner-only, transactionally audited issuance and revocation.
 *
 * The DB store performs a fresh approved-owner/active-client SQL check. An
 * audit insert and the credential mutation share a single PDO transaction;
 * any failure rolls back. Never log or serialize the returned bearer value.
 */
final readonly class OwnerCredentialService implements OwnerCredentialManager
{
    public function __construct(
        private PDO $pdo,
        private ApiCredentialStore $store,
        private CredentialActionLimiter $limiter,
        private SecurityEventRecorder $events,
    ) {
    }

    public function issue(int $ownerId, int $clientId, string $sourceIp, string $requestId): array
    {
        if ($ownerId < 1 || $clientId < 1) {
            return ['status' => 403];
        }
        if (!$this->limiter->allow($ownerId, $sourceIp)) {
            $this->events->record('credential_action_throttled', $requestId);
            return ['status' => 429];
        }

        $this->pdo->beginTransaction();
        try {
            $new = ApiKey::issue();
            $created = $this->store->createForOwner(
                $ownerId, $clientId, $new['prefix'], $new['digest'],
            );
            $this->events->record(
                $created ? 'credential_issued' : 'credential_issue_denied',
                $requestId,
            );
            $this->pdo->commit();
            if (!$created) {
                return ['status' => 403];
            }
            // Returned once to the HTTPS POST handler only, never the session.
            return ['status' => 200, 'key' => $new['key']];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function revoke(
        int $ownerId,
        int $clientId,
        int $credentialId,
        string $sourceIp,
        string $requestId,
    ): array {
        if ($ownerId < 1 || $clientId < 1 || $credentialId < 1) {
            return ['status' => 403];
        }
        if (!$this->limiter->allow($ownerId, $sourceIp)) {
            $this->events->record('credential_action_throttled', $requestId);
            return ['status' => 429];
        }

        $this->pdo->beginTransaction();
        try {
            $revoked = $this->store->revokeForOwner($ownerId, $clientId, $credentialId);
            $this->events->record(
                $revoked ? 'credential_revoked' : 'credential_revoke_denied',
                $requestId,
            );
            $this->pdo->commit();
            return ['status' => $revoked ? 200 : 403];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
