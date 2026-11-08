<?php

declare(strict_types=1);

namespace OPGateway\Security;

/**
 * Verify the origin of the one-time administrator setup form submission.
 *
 * A supplied Origin must match exactly. Some browser/hosting combinations
 * omit Origin on an ordinary HTML form navigation. In that case allow only
 * Fetch Metadata confirming same-origin navigation, with the independent
 * session CSRF, exact operator IP and setup-token checks still mandatory.
 *
 * Never trust an arbitrary Referer, Host, X-Forwarded-* or Origin as an
 * authorization substitute for the form's server-side CSRF token.
 */
final class SetupOriginPolicy
{
    /**
     * @param array<string,mixed> $server Server-provided request metadata.
     * @return 'setup.origin_mismatch'|'setup.origin_missing'|null
     */
    public static function denialEvent(string $expectedOrigin, array $server): ?string
    {
        $provided = $server['HTTP_ORIGIN'] ?? null;

        if ($provided !== null && $provided !== '') {
            return is_string($provided) && hash_equals($expectedOrigin, $provided)
                ? null : 'setup.origin_mismatch';
        }

        // Fail closed for absent Origin unless modern browser metadata
        // confirms a same-origin top-level HTML form submission.
        if (($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin'
            && ($server['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate') {
            return null;
        }

        return 'setup.origin_missing';
    }
}
