<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\AdministratorAuthorization;

/**
 * Private session-bound controller, NOT connected to public/index.php.
 *
 * The caller MUST have already started a session with verified HTTPS and
 * enforce stream-level body limits. Never pass actor/auth/role flags from
 * user-controlled request data.
 */
final readonly class AdminController
{
    public function __construct(
        private BrowserSession $session,
        private LoginAccountRepository $accounts,
        private AdministratorAuthorization $administrators,
        private AdminRequestPolicy $policy,
        private AdminActions $actions,
    ) {
    }

    /**
     * @return array{status:int,body:array<string,string>}
     */
    public function handle(
        string $method,
        bool $trustedHttps,
        ?string $origin,
        ?string $contentType,
        ?int $contentLength,
        string $body,
        string $csrfToken,
    ): array {
        // The session is expected to be active only after a trusted HTTPS
        // decision. Reject insecure transports BEFORE touching session state.
        if (!$trustedHttps) {
            return self::error(403);
        }

        $userId = $this->session->currentUserId($this->accounts);
        $isAdmin = $userId !== null && $this->administrators->isAdministrator($userId);
        $csrfValid = $this->session->csrfValid($csrfToken);
        $error = $this->policy->check(
            $method, $trustedHttps, $userId !== null, $isAdmin,
            $origin, $contentType, $contentLength, $csrfValid,
        );
        if ($error !== null) {
            return self::error($error);
        }

        // Reading Content-Length alone is not sufficient protection.
        if (strlen($body) !== $contentLength || strlen($body) > BoundedJson::MAX_BYTES) {
            return self::error(413);
        }

        $payload = BoundedJson::decode($body);
        if ($payload === null) {
            return self::error(400);
        }

        return $this->actions->dispatch($userId, $payload);
    }

    /** @return array{status:int,body:array<string,string>} */
    private static function error(int $status): array
    {
        return ['status' => $status, 'body' => ['error' => match ($status) {
            401 => 'unauthorized',
            403 => 'forbidden',
            405 => 'method_not_allowed',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            default => 'invalid_request',
        }]];
    }
}
