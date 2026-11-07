<?php

declare(strict_types=1);

namespace OPGateway\Http;

use JsonException;

/**
 * Strict JSON object parser for small administrative requests.
 *
 * Never include raw JSON, field values or parser exception messages in
 * production logs. Transport code must cap reads at MAX_BYTES + 1.
 */
final class BoundedJson
{
    public const MAX_BYTES = 16384;

    /** @return array<string, mixed>|null */
    public static function decode(string $body): ?array
    {
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        try {
            $value = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        // Top-level JSON must be an object; arrays and scalars are refused.
        if (!is_array($value) || !self::isObjectDocument($body)) {
            return null;
        }

        return $value;
    }

    private static function isObjectDocument(string $body): bool
    {
        return str_starts_with(ltrim($body), '{');
    }

    /** Reject extra input fields to prevent future accidental privilege expansion. */
    public static function fields(array $input, array $required): bool
    {
        $actual = array_keys($input);
        sort($actual);
        sort($required);
        return $actual === $required;
    }

    /** Strict unsigned positive integer; no PHP loose coercion. */
    public static function positiveId(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
