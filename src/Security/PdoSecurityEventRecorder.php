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
