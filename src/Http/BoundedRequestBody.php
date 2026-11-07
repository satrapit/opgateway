<?php

declare(strict_types=1);

namespace OPGateway\Http;

use RuntimeException;

/**
 * Reads a bounded HTTP body from an already-opened stream.
 *
 * Never records the body, which may contain passwords or CSRF tokens.
 * Return null when incomplete, oversized or inconsistent with a strict
 * Content-Length. A controller must reject null without further parsing.
 */
final class BoundedRequestBody
{
    public const MAX_BYTES = 16384;

    /** @param resource $stream @return string|null */
    public static function read($stream, ?string $contentLength): ?string
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('A request stream is required.');
        }
        if ($contentLength === null || preg_match('/^(?:0|[1-9][0-9]{0,5})$/D', $contentLength) !== 1) {
            return null;
        }
        $length = (int) $contentLength;
        if ($length > self::MAX_BYTES) {
            return null;
        }
        $body = stream_get_contents($stream, self::MAX_BYTES + 1);
        if ($body === false || strlen($body) !== $length || strlen($body) > self::MAX_BYTES) {
            return null;
        }
        return $body;
    }
}
