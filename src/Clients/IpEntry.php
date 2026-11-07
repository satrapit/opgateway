<?php

declare(strict_types=1);

namespace OPGateway\Clients;

use InvalidArgumentException;

/**
 * Canonical exact-address storage for approved API client source networks.
 *
 * CIDR ranges, HTTP forwarding headers, zone identifiers and hostnames are
 * deliberately unsupported. Database VARBINARY(16) stores packed bytes.
 */
final class IpEntry
{
    public static function pack(string $address): string
    {
        if ($address === '' || trim($address) !== $address || str_contains($address, '%')) {
            throw new InvalidArgumentException('Invalid IP address.');
        }

        $packed = @inet_pton($address);
        if ($packed === false || !in_array(strlen($packed), [4, 16], true)) {
            throw new InvalidArgumentException('Invalid IP address.');
        }

        return $packed;
    }

    public static function unpack(string $bytes): string
    {
        if (!in_array(strlen($bytes), [4, 16], true)) {
            throw new InvalidArgumentException('Invalid IP address bytes.');
        }

        $address = @inet_ntop($bytes);
        if ($address === false) {
            throw new InvalidArgumentException('Invalid IP address bytes.');
        }

        return $address;
    }
}
