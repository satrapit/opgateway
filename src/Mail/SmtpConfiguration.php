<?php

declare(strict_types=1);

namespace OPGateway\Mail;

use InvalidArgumentException;

/** Only TLS-protected authenticated SMTP is supported; never allow plaintext. */
final readonly class SmtpConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        public string $password,
        public string $fromEmail,
        public string $fromName,
    ) {
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')
            || preg_match('/(?:^|\.)localhost$|(?:^|\.)local$/iD', $host)) {
            throw new InvalidArgumentException('A public SMTP DNS hostname is required.');
        }
        if (!(($port === 587 && $encryption === 'starttls')
            || ($port === 465 && $encryption === 'smtps'))) {
            throw new InvalidArgumentException('SMTP must use STARTTLS:587 or SMTPS:465.');
        }
        if ($username === '' || strlen($username) > 254
            || preg_match('/[\x00-\x1f\x7f]/', $username)
            || $password === '' || strlen($password) > 4096) {
            throw new InvalidArgumentException('Invalid SMTP credentials.');
        }
        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false
            || strlen($fromEmail) > 254
            || $fromName === '' || strlen($fromName) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $fromName)) {
            throw new InvalidArgumentException('Invalid SMTP sender.');
        }
    }
}
