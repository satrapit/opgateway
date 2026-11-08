<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Observability\DatabaseFailure;
use OPGateway\Observability\DiagnosticLog;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DatabaseFailureTest extends TestCase
{
    public function testWhitelistedMysqlErrorIsClassifiedWithoutMessage(): void
    {
        $exception = new PDOException('password=sensitive');
        $exception->errorInfo = ['28000', 1045, 'Access denied for secret'];
        $result = DatabaseFailure::classify($exception);
        self::assertSame('authentication_rejected', $result['reason']);
        self::assertSame(1045, $result['mysql_errno']);
        self::assertSame('28', $result['sqlstate_class']);
    }

    public function testExtractsAllowlistedErrnoFromPdoConnectionPrefixOnly(): void
    {
        $exception = new PDOException(
            'SQLSTATE[HY000] [2002] socket=/private/secret/mysql.sock'
        );
        $exception->errorInfo = [];
        $result = DatabaseFailure::classify($exception);
        self::assertSame('connection_failure', $result['reason']);
        self::assertSame(2002, $result['mysql_errno']);
        self::assertSame('HY', $result['sqlstate_class']);
    }

    public function testClassifiesMysqlAuthenticationErrorWithoutErrorInfo(): void
    {
        $exception = new PDOException('SQLSTATE[HY000] [1045] Access denied');
        $exception->errorInfo = [];
        self::assertSame('authentication_rejected', DatabaseFailure::classify($exception)['reason']);
    }

    public function testIgnoresUntrustedNumericTokensOutsideOfficialPrefix(): void
    {
        $exception = new PDOException('password=2002 SQLSTATE[HY000] [1045]');
        $exception->errorInfo = [];
        $result = DatabaseFailure::classify($exception);
        self::assertNull($result['mysql_errno']);
        self::assertSame('unclassified', $result['reason']);
    }

    public function testUnrecognizedButValidNativeErrnoRemainsVisibleWithoutMessage(): void
    {
        $exception = new PDOException('SQLSTATE[HY000] [2026] secret certificate path');
        $exception->errorInfo = [];
        $result = DatabaseFailure::classify($exception);
        self::assertSame(2026, $result['mysql_errno']);
        self::assertSame('unclassified', $result['reason']);
    }

    public function testUnknownErrorCodeIsRedacted(): void
    {
        $exception = new PDOException('do not record this');
        $exception->errorInfo = ['ZZ999', 999999, 'secret'];
        $result = DatabaseFailure::classify($exception);
        self::assertSame('unclassified', $result['reason']);
        self::assertNull($result['mysql_errno']);
        self::assertNull($result['sqlstate_class']);
    }

    public function testLogIgnoresUntrustedContextAndExceptionMessage(): void
    {
        $dir = sys_get_temp_dir() . '/opgateway-db-log-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700));
        $file = $dir . '/events.jsonl';
        try {
            DiagnosticLog::initialize($file, str_repeat('a', 32));
            $exception = new PDOException('pass=secret');
            $exception->errorInfo = ['28000', 1045, 'pass=secret'];
            DiagnosticLog::record('setup.database_connection_failed', 503, $exception, [
                'operation' => 'connect',
                'host' => 'secret.hostname',
                'driver' => 'user controlled',
            ]);
            $data = file_get_contents($file);
            self::assertIsString($data);
            self::assertStringNotContainsString('secret', $data);
            self::assertStringNotContainsString('user controlled', $data);
            $record = json_decode(trim($data), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('connect', $record['context']['operation']);
            self::assertSame('authentication_rejected', $record['database']['reason']);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
