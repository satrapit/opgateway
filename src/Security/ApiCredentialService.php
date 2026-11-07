<?php

declare(strict_types=1);

namespace OPGateway\Security;

use RuntimeException;

/**
 * Internal lifecycle and authentication service, not an HTTP controller.
 *
 * Bearer credentials must only be returned once to an authorized owner over
 * HTTPS. A future controller must enforce sessions, CSRF, anti-abuse limits,
 * explicit permissions and safe audit metadata before calling this service.
 */
final readonly class ApiCredentialService
{
    public function __construct(
        private ApiCredentialStore $store,
        private ClientIpAccessPolicy $ipPolicy,
    ) {
    }

    /**
     * @return string A one-time plaintext bearer key; never write to logs.
     * @throws RuntimeException when issuing for this owner/client is denied.
     */
    public function issue(int $ownerUserId, int $clientId): string
    {
        $key = ApiKey::issue();
        if (!$this->store->createForOwner($ownerUserId, $clientId, $key['prefix'], $key['digest'])) {
            throw new RuntimeException('Credential issuance denied.');
        }

        return $key['key'];
    }

    /**
     * @return int|null Authorized client id; null means access denied.
     * The verified source address must come from the HTTP transport.
     */
    public function authenticate(string $bearerKey, string $sourceIp): ?int
    {
        $parsed = ApiKey::parse($bearerKey);
        if ($parsed === null) {
            return null;
        }

        $credential = $this->store->findActiveByPrefix($parsed['prefix']);
        if ($credential === null
            || $credential['client_id'] < 1
            || preg_match('/^[0-9a-f]{64}$/D', $credential['digest']) !== 1
            || !hash_equals($credential['digest'], $parsed['digest'])) {
            return null;
        }

        return $this->ipPolicy->allows($credential['client_id'], $sourceIp)
            ? $credential['client_id']
            : null;
    }

    public function revoke(int $ownerUserId, int $clientId, int $credentialId): bool
    {
        return $this->store->revokeForOwner($ownerUserId, $clientId, $credentialId);
    }
}
