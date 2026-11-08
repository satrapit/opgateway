<?php

declare(strict_types=1);

namespace OPGateway\Security;

use InvalidArgumentException;
use PDO;

/**
 * Append-only security event recorder with strictly constrained fields.
 *
 * No email, address, bearer token, password, body, header or exception text
 * is persisted. Failure propagates to the caller rather than being hidden.
 */
final readonly class PdoSecurityEventRecorder implements SecurityEventRecorder
{
    private const EVENTS = [
        'login_allowed',
        'login_denied',
        'login_throttled',
        'admin_review_throttled',
        'admin_approve_user_allowed',
        'admin_approve_user_denied',
        'admin_activate_client_allowed',
        'admin_activate_client_denied',
        'admin_approve_ip_allowed',
        'admin_approve_ip_denied',
        'admin_create_client_allowed',
        'admin_create_client_denied',
        'admin_request_ip_allowed',
        'admin_request_ip_denied',
        'admin_disable_client_allowed',
        'admin_disable_client_denied',
        'admin_revoke_ip_allowed',
        'admin_revoke_ip_denied',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function record(string $eventCode, string $requestId): void
    {
        if (!in_array($eventCode, self::EVENTS, true)
            || preg_match('/^[0-9a-f]{32}$/D', $requestId) !== 1) {
            throw new InvalidArgumentException('Unsupported security event metadata.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO security_events (event_code, request_id, created_at)
VALUES (?, ?, UTC_TIMESTAMP(6))
SQL);
        $statement->execute([$eventCode, $requestId]);
    }
}
