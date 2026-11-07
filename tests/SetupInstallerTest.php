<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\SetupInstaller;
use PHPUnit\Framework\TestCase;

final class SetupInstallerTest extends TestCase
{
    public function testInstallerIsDisabledByDefault(): void
    {
        $result = (new SetupInstaller([]))->handle('GET', []);
        self::assertSame(404, $result['status']);
        self::assertStringNotContainsString('password', $result['body']);
    }

    public function testInstallerRejectsOtherIpAndNonHttps(): void
    {
        $installer = new SetupInstaller([
            'SETUP_ENABLED' => 'true',
            'SETUP_ALLOWED_IP' => '192.0.2.10',
        ]);
        self::assertSame(404, $installer->handle('GET',
            ['HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.11'])['status']);
        self::assertSame(404, $installer->handle('GET',
            ['HTTPS' => 'off', 'REMOTE_ADDR' => '192.0.2.10'])['status']);
        self::assertSame(404, $installer->handle('GET',
            ['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.2',
             'HTTP_X_FORWARDED_FOR' => '192.0.2.10'])['status']);
    }
}
