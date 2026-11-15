<?php

declare(strict_types=1);

namespace OPGateway\Http;

use OPGateway\Identity\EmailVerificationService;

/**
 * Gmail ConfirmAction POST handler, separate from browser CSRF verification.
 * The existing single-use email token authorizes the operation. User-Agent
 * filtering is only a compatibility check and MUST NOT be treated as
 * cryptographic proof of Google's identity.
 */
final readonly class GmailVerificationAction
{
    public function __construct(
        private EmailVerificationService $verification,
        private bool $enabled,
    ) {
    }

    /** @return array{status:int,content_type:string,body:string} */
    public function handle(
        string $method, ?string $contentType, ?string $body,
        string $query, string $userAgent,
    ): array {
        $status = 404;
        if ($this->enabled && $method === 'POST') {
            $status = 400;
            if (preg_match('/^token=([a-f0-9]{64})$/D', $query, $matches) === 1
                && $contentType !== null
                && preg_match('~^application/x-www-form-urlencoded(?:\\s*;\\s*charset=utf-8)?$~iD', $contentType) === 1
                && str_contains($userAgent, 'Gmail Actions')
                && $body !== null
                && strlen($body) <= 1024) {
                $form = StrictForm::decode($body, ['confirmed']);
                if ($form !== null && $form['confirmed'] === 'Approved') {
                    $status = $this->verification->verify($matches[1]) ? 200 : 400;
                }
            }
        }
        return [
            'status' => $status,
            'content_type' => 'text/plain; charset=utf-8',
            'body' => $status === 200 ? 'OK' : 'Action unavailable',
        ];
    }
}
