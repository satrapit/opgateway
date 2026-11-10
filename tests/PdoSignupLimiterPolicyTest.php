<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Security\PdoSignupLimiter;
use PHPUnit\Framework\TestCase;

final class PdoSignupLimiterPolicyTest extends TestCase
{
    public function testRejectsMalformedTransportIpBeforeDatabaseUse(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $limiter = new PdoSignupLimiter($pdo, str_repeat('x', 32));
        self::assertFalse($limiter->allow('not-an-ip', 'test@example.org'));
    }
}
