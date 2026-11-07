<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/**
 * Internal use only until an HTTP entrypoint enforces CSRF, verification,
 * throttling, enumeration resistance and bot-abuse protections.
 */
final readonly class RegistrationService
{
    public function __construct(
        private UserRepository $repository,
        private PasswordHasher $passwordHasher,
    ) {
    }

    /** @return int Identifier for a pending, unverified user. */
    public function register(RegistrationData $data): int
    {
        return $this->repository->insertPending(
            $data->email,
            $this->passwordHasher->hash($data->passwordForHashing()),
        );
    }
}
