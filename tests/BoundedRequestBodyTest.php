<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Http\BoundedRequestBody;
use PHPUnit\Framework\TestCase;

final class BoundedRequestBodyTest extends TestCase
{
    /** @return resource */
    private function stream(string $body)
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $body);
        rewind($stream);
        return $stream;
    }

    public function testReadsExactContentLength(): void
    {
        $stream = $this->stream('hello');
        self::assertSame('hello', BoundedRequestBody::read($stream, '5'));
        fclose($stream);
    }

    public function testRejectsInconsistentAndMissingContentLength(): void
    {
        $stream = $this->stream('hello');
        self::assertNull(BoundedRequestBody::read($stream, '4'));
        fclose($stream);
        $stream = $this->stream('hello');
        self::assertNull(BoundedRequestBody::read($stream, null));
        fclose($stream);
    }

    public function testRejectsOversizedBodiesAndMalformedLength(): void
    {
        $stream = $this->stream('x');
        self::assertNull(BoundedRequestBody::read($stream, '16385'));
        fclose($stream);
        $stream = $this->stream('x');
        self::assertNull(BoundedRequestBody::read($stream, '1, 1'));
        fclose($stream);
    }
}
