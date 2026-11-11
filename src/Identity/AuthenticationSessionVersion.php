<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;

/** Opaque browser-session marker derived only from a server-controlled counter. */
final class AuthenticationSessionVersion
{
    public static function stamp(int $version): string
    {
        if ($version < 1) {
            throw new InvalidArgumentException('Invalid authentication session version.');
        }

        return hash('sha256', "opgateway/auth-session/v1\0" . (string) $version);
    }
}
