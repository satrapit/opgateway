<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;

/**
 * Side-effect-free HTTP admission policy for future session-authenticated
 * administrative actions. This policy does not establish authentication.
 *
 * The calling controller must obtain $authenticated and $csrfValid from
 * trusted server-side session state and $isAdmin from a fresh DB query.
 * Never accept these flags from request parameters or headers.
 */
final readonly class AdminRequestPolicy
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(private string $canonicalOrigin)
    {
        $parts = parse_url($canonicalOrigin);
        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'], $parts['pass'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('/^https:\/\/[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/D', $canonicalOrigin)) {
            throw new InvalidArgumentException('Invalid canonical admin origin.');
        }
    }

    /**
     * @param string $method Trusted REQUEST_METHOD (not X-HTTP-Method-Override).
     * @param string|null $origin Raw Origin header; null denotes missing.
     * @param string|null $contentType Raw Content-Type.
     * @param int|null $contentLength Validated request Content-Length, or null.
     * @return int|null HTTP error code, or null if all policy checks pass.
     */
    public function check(
        string $method,
        bool $https,
        bool $authenticated,
        bool $isAdmin,
        ?string $origin,
        ?string $contentType,
        ?int $contentLength,
        bool $csrfValid,
    ): ?int {
        if (!$https) {
            return 403;
        }
        if (!$authenticated) {
            return 401;
        }
        if (!$isAdmin) {
            return 403;
        }
        if ($method !== 'POST') {
            return 405;
        }
        // Require a strict same-origin POST, not merely a matching Referer.
        if ($origin === null || !hash_equals($this->canonicalOrigin, $origin)) {
            return 403;
        }
        if ($contentLength === null || $contentLength < 0
            || $contentLength > self::MAX_BODY_BYTES) {
            return 413;
        }
        if ($contentType === null
            || preg_match('/^application\/json(?:\s*;\s*charset=utf-8)?$/iD', $contentType) !== 1) {
            return 415;
        }
        if (!$csrfValid) {
            return 403;
        }

        return null;
    }
}
