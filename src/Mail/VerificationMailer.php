<?php

declare(strict_types=1);

namespace OPGateway\Mail;

/** Internal mail boundary; implementations must never log tokens or passwords. */
interface VerificationMailer
{
    public function sendVerification(string $email, string $token, string $appUrl): void;
}
