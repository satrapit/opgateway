<?php

declare(strict_types=1);

namespace OPGateway\Http;

/**
 * Strict parser for small, URL-encoded login/logout requests.
 *
 * Duplicate keys, array syntax, malformed escapes and arbitrary parameters
 * are rejected; PHP's parse_str() silently overwrites duplicate keys.
 */
final class StrictForm
{
    /** @param list<string> $expected @return array<string,string>|null */
    public static function decode(string $body, array $expected): ?array
    {
        if ($body === '' || strlen($body) > BoundedRequestBody::MAX_BYTES) {
            return null;
        }

        $result = [];
        foreach (explode('&', $body) as $entry) {
            if ($entry === '' || !str_contains($entry, '=')) {
                return null;
            }
            [$key, $value] = explode('=', $entry, 2);
            if ($key === '' || preg_match('/%(?![0-9a-fA-F]{2})/', $key . $value)) {
                return null;
            }
            $decodedKey = urldecode($key);
            if (!in_array($decodedKey, $expected, true) || array_key_exists($decodedKey, $result)) {
                return null;
            }
            $result[$decodedKey] = urldecode($value);
        }
        $receivedKeys = array_keys($result);
        sort($receivedKeys);
        sort($expected);
        if ($receivedKeys !== $expected) {
            return null;
        }
        return $result;
    }
}
