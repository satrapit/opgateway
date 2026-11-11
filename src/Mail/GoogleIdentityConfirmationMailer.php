<?php

declare(strict_types=1);

namespace OPGateway\Mail;

/** One-time mailbox proof before binding Google to an existing account. */
interface GoogleIdentityConfirmationMailer
{
    public function sendGoogleIdentityConfirmation(
        string $email, string $token, string $origin,
    ): void;
}
