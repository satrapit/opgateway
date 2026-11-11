<?php

declare(strict_types=1);

namespace OPGateway\Mail;

/**
 * Dedicated email invitation boundary; never sends initial passwords.
 * Verification links are handled by the existing one-time token flow.
 */
interface InvitationMailer
{
    public function sendInvitation(string $email, string $token, string $origin): void;
}
