<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\PdoAdministratorAuthorization;
use RuntimeException;

/**
 * Private login/logout boundary for an eventual same-origin browser adapter.
 *
 * NOT registered in public/index.php. The HTTP adapter must verify HTTPS
 * from server-controlled metadata, start the secure session, supply a
 * CSPRNG-generated request ID and read a bounded form body before calling.
 *
 * Nothing here authorizes arbitrary users to administer the gateway.
 */
final readonly class BrowserAuthController
{
    public function __construct(
        private BrowserSession $session,
        private GuardedLoginService $login,
        private LoginAccountRepository $accounts,
        private PdoAdministratorAuthorization $admins,
        private string $canonicalOrigin,
    ) {
        if (!preg_match('/^https:\/\/[a-z0-9.-]+(?::[0-9]{1,5})?$/iD', $canonicalOrigin)) {
            throw new RuntimeException('Invalid browser authentication origin.');
        }
    }

    /**
     * Authenticate an administrator using an existing secure browser session.
     * All fields come from strictly parsed POST data; trust flags never do.
     *
     * @return array{status:int,body:array<string,string>}
     */
    public function login(
        bool $trustedHttps,
        string $method,
        ?string $origin,
        ?string $contentType,
        string $sourceIp,
        string $requestId,
        string $csrfToken,
        string $email,
        string $password,
    ): array {
        if (!$this->validMutation($trustedHttps, $method, $origin, $contentType, $csrfToken)) {
            return self::denied(403, 'forbidden');
        }

        // The password is never logged, included in a response or stored in
        // the browser session. GuardedLoginService reserves attempts first.
        $userId = $this->login->authenticate($email, $password, $sourceIp, $requestId);
        if ($userId === null || !$this->admins->isAdministrator($userId)) {
            return self::denied(401, 'invalid_credentials');
        }

        $this->session->authenticate($userId);
        return ['status' => 200, 'body' => ['status' => 'authenticated']];
    }

    /**
     * Logout requires both an eligible current account and a valid CSRF
     * token, so an unrelated site cannot silently terminate a session.
     *
     * @return array{status:int,body:array<string,string>}
     */
    public function logout(
        bool $trustedHttps,
        string $method,
        ?string $origin,
        ?string $contentType,
        string $csrfToken,
    ): array {
        if (!$this->validMutation($trustedHttps, $method, $origin, $contentType, $csrfToken)) {
            return self::denied(403, 'forbidden');
        }
        if ($this->session->currentUserId($this->accounts) === null) {
            return self::denied(401, 'unauthorized');
        }

        $this->session->logout();
        return ['status' => 200, 'body' => ['status' => 'logged_out']];
    }

    private function validMutation(
        bool $https,
        string $method,
        ?string $origin,
        ?string $contentType,
        string $csrfToken,
    ): bool {
        return $https
            && $method === 'POST'
            && $origin !== null
            && hash_equals($this->canonicalOrigin, $origin)
            && $contentType !== null
            && preg_match('/^application\/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$/iD', $contentType) === 1
            && $this->session->csrfValid($csrfToken);
    }

    /** @return array{status:int,body:array<string,string>} */
    private static function denied(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['error' => $code]];
    }
}
