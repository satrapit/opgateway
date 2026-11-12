<?php

declare(strict_types=1);

namespace OPGateway\Google;

/** Provider boundary to test the OAuth callback without external network. */
interface GoogleOidcProvider
{
    /** @return array{sub:string,email:string,email_verified:bool,email_authoritative:bool,given_name?:string,family_name?:string} */
    public function exchange(
        string $code, string $clientId, string $clientSecret,
        string $origin, string $verifier, string $nonce,
        bool $requireRecentAuthentication,
    ): array;
}
