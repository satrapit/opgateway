<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Observability\DiagnosticLog;
use PHPUnit\Framework\TestCase;

final class DiagnosticLogTest extends TestCase
{
    public function testLogsOnlySafeEventMetadata(): void
    {
        $directory = sys_get_temp_dir() . '/opgateway-logging-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $filename = $directory . '/test.jsonl';
        $id = str_repeat('a', 32);
        try {
            DiagnosticLog::initialize($filename, $id);
            DiagnosticLog::record(
                'setup.database_preflight_failed',
                503,
                new \RuntimeException('SECRET=password&setup_token=do-not-log'),
            );
            $json = file_get_contents($filename);
            self::assertIsString($json);
            self::assertStringNotContainsString('SECRET', $json);
            self::assertStringNotContainsString('do-not-log', $json);
            $data = json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($id, $data['request_id']);
            self::assertSame('setup.database_preflight_failed', $data['event']);
            self::assertSame('runtime', $data['error_category']);
        } finally {
            @unlink($filename);
            @rmdir($directory);
        }
    }
}
