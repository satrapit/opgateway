<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Security\PdoSetupAttemptLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoSetupAttemptLimiterTest extends TestCase
{
    public function testRejectsWeakSetupLimitKeyBeforeDatabaseAccess(): void
    {
        // In-memory SQLite PDO is used only to verify constructor validation.
        // Actual atomic MySQL behavior requires a separate integration test.
        $pdo = new PDO('sqlite::memory:');
        $this->expectException(InvalidArgumentException::class);
        new PdoSetupAttemptLimiter($pdo, 'weak');
    }
}
