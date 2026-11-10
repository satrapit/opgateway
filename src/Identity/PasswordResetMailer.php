<?php

declare(strict_types=1);

namespace OPGateway\Identity;

/** One-time password reset email transport. */
interface PasswordResetMailer
{
    public function sendPasswordReset(string $email, string $token, string $origin): void;
}
