<?php

declare(strict_types=1);

namespace OPGateway\Security;

/** Evaluate exact IP allowlisting without relying on spoofable proxy headers. */
final class IpAddress
{
    /** @param list<string> $allowed */
    public static function allowed(string $remoteAddress, array $allowed): bool
    {
        $source = @inet_pton($remoteAddress);
        if ($source === false) {
            return false;
        }
        foreach ($allowed as $entry) {
            $candidate = @inet_pton($entry);
            if ($candidate !== false && hash_equals($candidate, $source)) {
                return true;
            }
        }
        return false;
    }
}
