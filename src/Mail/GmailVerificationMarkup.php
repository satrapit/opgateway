<?php

declare(strict_types=1);

namespace OPGateway\Mail;

use InvalidArgumentException;

/**
 * Gmail inbox ConfirmAction. The URL embeds the existing 30-minute,
 * single-use email verification capability; never log or persist the URL.
 */
final class GmailVerificationMarkup
{
    public static function render(string $origin, string $token): string
    {
        if (preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~iD', $origin) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid Gmail verification action.');
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'EmailMessage',
            'potentialAction' => [
                '@type' => 'ConfirmAction',
                'name' => 'Verify Email',
                'handler' => [
                    '@type' => 'HttpActionHandler',
                    'url' => $origin . '/account/verify/gmail?token=' . $token,
                ],
            ],
            'description' => 'Verify your OPGateway email address',
        ];
        return '<script type="application/ld+json">'
            . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>';
    }
}
