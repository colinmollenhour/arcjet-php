<?php

declare(strict_types=1);

namespace Arcjet\Tests;

use Arcjet\Options;
use Arcjet\Reason\ErrorReason;
use Arcjet\Tests\Support\FakeHttpClient;
use Arcjet\Tests\Support\SdkTestCase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class StreamSafetyTest extends SdkTestCase
{
    public function testUnknownSizeOversizedResponseOnlyReadsLimitPlusOne(): void
    {
        $bytesRead = 0;
        $body = $this->createMock(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('getSize')->willReturn(null);
        $body->expects(self::once())->method('read')->willReturnCallback(static function (int $length) use (&$bytesRead): string {
            $bytesRead += $length;
            return str_repeat('x', $length);
        });
        $body->expects(self::once())->method('close');
        $http = new FakeHttpClient(static fn (): ResponseInterface => (new Response(200, ['Content-Type' => 'application/proto']))->withBody($body));
        $decision = $this->sdk($http, options: new Options(maxResponseBytes: 10))->protect($this->request());
        self::assertTrue($decision->isErrored());
        self::assertSame(11, $bytesRead);
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertSame('response_too_large', $decision->reason->code);
    }

    public function testNoProgressResponseCannotLoopForever(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('getSize')->willReturn(null);
        $body->expects(self::once())->method('read')->willReturn('');
        $body->method('eof')->willReturn(false);
        $body->expects(self::once())->method('close');
        $http = new FakeHttpClient(static fn (): ResponseInterface => (new Response(200, ['Content-Type' => 'application/proto']))->withBody($body));
        self::assertTrue($this->sdk($http)->protect($this->request())->isErrored());
    }

    public function testReadFailureRestoresOptedInRequestBodyPointer(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('isSeekable')->willReturn(true);
        $body->method('isReadable')->willReturn(true);
        $body->method('getSize')->willReturn(null);
        $body->method('tell')->willReturn(17);
        $body->expects(self::once())->method('rewind');
        $body->expects(self::once())->method('read')->willThrowException(new \RuntimeException('sensitive stream diagnostic'));
        $body->expects(self::once())->method('seek')->with(17);
        $http = $this->allowingHttp();
        $decision = $this->sdk($http, options: new Options(includeBody: true))->protect($this->request()->withBody($body));
        self::assertTrue($decision->isErrored());
        self::assertCount(0, $http->requests);
    }

    public function testBodyAtExactCapAndEmptyBodyAtZeroCapWork(): void
    {
        foreach (['', '1234567890'] as $value) {
            $body = Stream::create($value);
            $body->seek(strlen($value));
            $http = $this->allowingHttp();
            $decision = $this->sdk($http, options: new Options(includeBody: true, maxBodyBytes: strlen($value)))->protect($this->request()->withBody($body));
            self::assertTrue($decision->isAllowed());
            self::assertSame(strlen($value), $body->tell());
            $details = self::decodeRequest($http->requests[0])->getDetails();
            self::assertNotNull($details);
            self::assertSame($value, $details->getBody());
        }
    }

    public function testUnreadableOptInBodyNeverTouchesPositionOrNetwork(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('isSeekable')->willReturn(true);
        $body->method('isReadable')->willReturn(false);
        $body->expects(self::never())->method('read');
        $body->expects(self::never())->method('tell');
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http, options: new Options(includeBody: true))->protect($this->request()->withBody($body))->isErrored());
        self::assertCount(0, $http->requests);
    }

    public function testSeekableResponseIsReadFromBeginningEvenIfTransportLeftPointerAtEnd(): void
    {
        $response = self::response();
        $response->getBody()->seek(0, SEEK_END);
        $http = new FakeHttpClient(static fn (): ResponseInterface => $response);
        self::assertTrue($this->sdk($http)->protect($this->request())->isAllowed());
    }
}
