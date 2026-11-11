<?php

declare(strict_types=1);

namespace OPGateway\Google;

/** Provider boundary to test the OAuth callback without external network. */
interface GoogleOidcProvider
{
    /** @return array{sub:string,email:string} */
    public function exchange(
        string $code, string $clientId, string $clientSecret,
        string $origin, string $verifier, string $nonce,
    ): array;
}
