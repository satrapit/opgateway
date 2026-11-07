<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\SecurityEventRecorder;

/**
 * Internal login entrypoint coordinating pre-authentication controls.
 *
 * Never call LoginAuthenticator directly from an HTTP controller.
 * Request IDs must come from trusted, freshly generated server metadata.
 */
final readonly class GuardedLoginService
{
    public function __construct(
        private LoginAuthenticator $authenticator,
        private LoginAttemptLimiter $limiter,
        private SecurityEventRecorder $events,
    ) {
    }

    /** Null denotes generic denied login, without revealing the cause. */
    public function authenticate(
        string $email,
        string $password,
        string $sourceIp,
        string $requestId,
    ): ?int {
        if (!$this->limiter->allow($sourceIp, $email)) {
            $this->events->record('login_throttled', $requestId);
            return null;
        }

        $userId = $this->authenticator->authenticate($email, $password);
        $this->events->record($userId === null ? 'login_denied' : 'login_allowed', $requestId);
        return $userId;
    }
}
