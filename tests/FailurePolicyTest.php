<?php

declare(strict_types=1);

namespace Arcjet\Tests;

use Arcjet\Arcjet;
use Arcjet\Conclusion;
use Arcjet\Decision;
use Arcjet\FailureKind;
use Arcjet\Internal\Proto;
use Arcjet\Middleware\ArcjetMiddleware;
use Arcjet\Options;
use Arcjet\Reason\ErrorReason;
use Arcjet\Reason\GenericReason;
use Arcjet\Tests\Support\FakeHttpClient;
use Arcjet\Tests\Support\NetworkFailure;
use Arcjet\Tests\Support\PreparedRequestFailure;
use Arcjet\Tests\Support\SdkTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FailurePolicyTest extends SdkTestCase
{
    public function testDecisionConstructorRemainsPositionallyCompatibleAndUnknownOriginIsClosed(): void
    {
        $decision = new Decision('test', Conclusion::Error, new GenericReason(), [], null, 17);
        self::assertSame(17, $decision->ttl);
        self::assertNull($decision->failureKind);
        self::assertFalse($decision->isFailOpenEligible());
        self::assertTrue($decision->isErrored());
        self::assertFalse($decision->isAllowed());
        self::assertFalse($decision->isDenied());
    }

    public function testFailOpenEligibilityRequiresBothErrorConclusionAndExplicitOperationalKind(): void
    {
        foreach (Conclusion::cases() as $conclusion) {
            foreach ([null, ...FailureKind::cases()] as $kind) {
                $decision = new Decision('test', $conclusion, new GenericReason(), failureKind: $kind);
                $eligible = $conclusion === Conclusion::Error && in_array($kind, [FailureKind::Transport, FailureKind::Service], true);
                self::assertSame($eligible, $decision->isFailOpenEligible(), $conclusion->name . '/' . ($kind->name ?? 'unclassified'));
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function requestFailures(): iterable
    {
        foreach (['missing_ip', 'invalid_ip', 'invalid_forwarded_ip', 'secret_in_request', 'padded_body', 'unseekable_body', 'unreadable_body', 'request_cap', 'header_cap', 'utf8_expansion_cap', 'raw_target_cap'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('requestFailures')]
    public function testRequestLocalFailuresStayClosedEvenWhenFailOpenIsEnabled(string $case): void
    {
        $request = $this->request();
        $options = new Options();
        $code = 'request_too_large';
        $body = null;
        switch ($case) {
            case 'missing_ip':
                $request = new ServerRequest('GET', 'https://example.test/');
                $code = 'invalid_ip';
                break;
            case 'invalid_ip':
                $request = $this->request('not an IP');
                $code = 'invalid_ip';
                break;
            case 'invalid_forwarded_ip':
                $request = $this->request('10.0.0.1')->withHeader('X-Forwarded-For', 'unknown');
                $options = new Options(trustedProxies: ['10.0.0.1']);
                $code = 'invalid_forwarded_ip';
                break;
            case 'secret_in_request':
                $request = $request->withHeader('X-Accidental-Key', self::KEY);
                $code = 'secret_in_request';
                break;
            case 'padded_body':
                $body = Stream::create('123456789');
                $body->seek(4);
                $request = $request->withBody($body);
                $options = new Options(includeBody: true, maxBodyBytes: 3);
                $code = 'request_body';
                break;
            case 'unseekable_body':
            case 'unreadable_body':
                $unsafeBody = $this->createMock(StreamInterface::class);
                $unsafeBody->method('isSeekable')->willReturn($case === 'unreadable_body');
                $unsafeBody->method('isReadable')->willReturn(false);
                $unsafeBody->expects(self::never())->method('read');
                $unsafeBody->expects(self::never())->method('tell');
                $request = $request->withBody($unsafeBody);
                $options = new Options(includeBody: true);
                $code = 'request_body';
                break;
            case 'request_cap':
                $request = $request->withUri(new \Nyholm\Psr7\Uri('https://example.test/?q=' . str_repeat('a', 1048576)));
                break;
            case 'header_cap':
                $request = $request->withHeader('X-Large', str_repeat('a', 1048576));
                break;
            case 'utf8_expansion_cap':
                $request = $request->withHeader('User-Agent', str_repeat("\xe9", 600000));
                break;
            case 'raw_target_cap':
                $request = new ServerRequest('GET', 'https://example.test/', [], null, '1.1', [
                    'REMOTE_ADDR' => '198.51.100.42', 'REQUEST_URI' => '/' . str_repeat('a', 1048576),
                ]);
                break;
        }
        $http = $this->allowingHttp();
        $sdk = $this->sdk($http, options: $options);
        $decision = $sdk->protect($request);
        self::assertSame(FailureKind::Request, $decision->failureKind);
        self::assertTrue($decision->isErrored());
        self::assertFalse($decision->isFailOpenEligible());
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertSame($code, $decision->reason->code);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $response = (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($request, $handler);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertCount(0, $http->requests);
        if ($body !== null) {
            self::assertSame(4, $body->tell());
        }
    }

    public function testInvalidExplicitIpAndCorrelationAreRequestFailures(): void
    {
        $http = $this->allowingHttp();
        $sdk = $this->sdk($http);
        foreach ([$sdk->protect($this->request(), ip: 'invalid'), $sdk->protect($this->request(), correlationId: "invalid\n")] as $decision) {
            self::assertSame(FailureKind::Request, $decision->failureKind);
            self::assertFalse($decision->isFailOpenEligible());
        }
        self::assertCount(0, $http->requests);
    }

    /** @return iterable<string, array{string, FailureKind, int}> */
    public static function sendFailures(): iterable
    {
        yield 'explicit PSR network outage' => ['network', FailureKind::Transport, 204];
        yield 'explicit PSR client operational failure' => ['client', FailureKind::Transport, 204];
        yield 'invalid prepared request' => ['request', FailureKind::Internal, 503];
        yield 'generic runtime exception' => ['runtime', FailureKind::Internal, 503];
        yield 'generic exception' => ['exception', FailureKind::Internal, 503];
        yield 'programming error' => ['error', FailureKind::Internal, 503];
    }

    #[DataProvider('sendFailures')]
    public function testOnlyPsrOperationalSendFailuresAreTransport(string $failure, FailureKind $kind, int $status): void
    {
        $http = new FakeHttpClient(static function (RequestInterface $request) use ($failure): ResponseInterface {
            throw match ($failure) {
                'network' => new NetworkFailure($request),
                'client' => new class('sensitive diagnostic') extends \RuntimeException implements ClientExceptionInterface {},
                'request' => new PreparedRequestFailure($request),
                'exception' => new \Exception('sensitive diagnostic'),
                'error' => new \TypeError('sensitive diagnostic'),
                default => new \RuntimeException('sensitive diagnostic'),
            };
        });
        $sdk = $this->sdk($http);
        $decision = $sdk->protect($this->request());
        self::assertSame($kind, $decision->failureKind);
        self::assertSame($kind === FailureKind::Transport, $decision->isFailOpenEligible());
        self::assertTrue($decision->isErrored());
        self::assertFalse($decision->isAllowed());
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertStringNotContainsString('sensitive', strtolower($decision->reason->message));
        $handler = $this->createMock(RequestHandlerInterface::class);
        if ($status === 204) {
            $handler->expects(self::once())->method('handle')->with(self::callback(static function ($request): bool {
                if (!$request instanceof \Psr\Http\Message\ServerRequestInterface) {
                    return false;
                }
                $attached = $request->getAttribute(ArcjetMiddleware::DECISION_ATTRIBUTE);
                return $attached instanceof Decision && $attached->isErrored() && !$attached->isAllowed() && $attached->failureKind === FailureKind::Transport;
            }))->willReturn(new Response(204));
        } else {
            $handler->expects(self::never())->method('handle');
        }
        $response = (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($this->request(), $handler);
        self::assertSame($status, $response->getStatusCode());
        $closedHandler = $this->createMock(RequestHandlerInterface::class);
        $closedHandler->expects(self::never())->method('handle');
        self::assertSame(503, (new ArcjetMiddleware($sdk, new Psr17Factory()))->process($this->request(), $closedHandler)->getStatusCode());
        self::assertCount(3, $http->requests);
    }

    public function testPsrExceptionOutsideSendBoundaryIsInternalAndCannotFailOpen(): void
    {
        $http = $this->allowingHttp();
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::exactly(2))->method('createRequest')->willThrowException(new NetworkFailure(new Request('POST', 'https://example.test/')));
        $sdk = new Arcjet(self::KEY, $http, $requestFactory, new Psr17Factory());
        self::assertSame(FailureKind::Internal, $sdk->protect($this->request())->failureKind);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        self::assertSame(503, (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($this->request(), $handler)->getStatusCode());
        self::assertCount(0, $http->requests);
    }

    public function testStreamFactoryRuntimeFaultIsInternalAndCannotFailOpen(): void
    {
        $http = $this->allowingHttp();
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->expects(self::exactly(2))->method('createStream')->willThrowException(new \RuntimeException('sensitive factory diagnostic'));
        $sdk = new Arcjet(self::KEY, $http, new Psr17Factory(), $streamFactory);
        self::assertSame(FailureKind::Internal, $sdk->protect($this->request())->failureKind);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        self::assertSame(503, (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($this->request(), $handler)->getStatusCode());
        self::assertCount(0, $http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function remoteErrorReasons(): iterable
    {
        foreach (['missing', 'generic', 'error', 'bot'] as $variant) {
            yield $variant => [$variant];
        }
    }

    #[DataProvider('remoteErrorReasons')]
    public function testRemoteErrorOriginDoesNotDependOnReasonVariant(string $variant): void
    {
        $proto = (new Proto\Decision())->setConclusion(Proto\Conclusion::CONCLUSION_ERROR);
        $reason = match ($variant) {
            'generic' => (new Proto\Reason())->setEdgeRule(new Proto\EdgeRuleReason()),
            'error' => (new Proto\Reason())->setError((new Proto\ErrorReason())->setMessage('private remote diagnostic')),
            'bot' => (new Proto\Reason())->setBotV2(new Proto\BotV2Reason()),
            default => null,
        };
        if ($reason !== null) {
            $proto->setReason($reason);
        }
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response($proto));
        $sdk = $this->sdk($http);
        $decision = $sdk->protect($this->request());
        self::assertSame(FailureKind::Service, $decision->failureKind);
        self::assertTrue($decision->isFailOpenEligible());
        self::assertFalse($decision->isAllowed());
        self::assertFalse($decision->isDenied());
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response(204));
        self::assertSame(204, (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($this->request(), $handler)->getStatusCode());
        $closedHandler = $this->createMock(RequestHandlerInterface::class);
        $closedHandler->expects(self::never())->method('handle');
        self::assertSame(503, (new ArcjetMiddleware($sdk, new Psr17Factory()))->process($this->request(), $closedHandler)->getStatusCode());
    }

    /** @return iterable<string, array{int, string, string, string}> */
    public static function serviceFailures(): iterable
    {
        yield from ArcjetTest::badResponses();
        yield 'unsupported encoding' => [200, 'application/proto', 'compressed', 'content_encoding'];
        yield 'response cap' => [200, 'application/proto', str_repeat('a', 30), 'response_too_large'];
        yield 'invalid protobuf string UTF8' => [200, 'application/proto', "\x0a\x05\x0a\x01\xff\x10\x01", 'malformed_response'];
    }

    #[DataProvider('serviceFailures')]
    public function testServiceResponseFailuresAreOperationalAndOnlyExplicitlyFailOpen(int $status, string $type, string $body, string $code): void
    {
        $headers = ['Content-Type' => $type];
        if ($code === 'content_encoding') {
            $headers['Content-Encoding'] = 'gzip';
        }
        $http = new FakeHttpClient(static fn (): ResponseInterface => new Response($status, $headers, $body));
        $sdk = $this->sdk($http, options: new Options(maxResponseBytes: $code === 'response_too_large' ? 10 : 1048576));
        $decision = $sdk->protect($this->request());
        self::assertSame(FailureKind::Service, $decision->failureKind);
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertSame($code, $decision->reason->code);
        self::assertTrue($decision->isFailOpenEligible());
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response(204));
        self::assertSame(204, (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($this->request(), $handler)->getStatusCode());
        $closedHandler = $this->createMock(RequestHandlerInterface::class);
        $closedHandler->expects(self::never())->method('handle');
        self::assertSame(503, (new ArcjetMiddleware($sdk, new Psr17Factory()))->process($this->request(), $closedHandler)->getStatusCode());
    }

    public function testArbitraryResponseStreamRuntimeFaultRemainsInternal(): void
    {
        $http = new FakeHttpClient(function (): ResponseInterface {
            $body = $this->createStub(StreamInterface::class);
            $body->method('isReadable')->willReturn(true);
            $body->method('getSize')->willReturn(null);
            $body->method('read')->willThrowException(new \RuntimeException('sensitive stream diagnostic'));
            return (new Response(200, ['Content-Type' => 'application/proto']))->withBody($body);
        });
        $sdk = $this->sdk($http);
        self::assertSame(FailureKind::Internal, $sdk->protect($this->request())->failureKind);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        self::assertSame(503, (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($this->request(), $handler)->getStatusCode());
    }

    public function testArbitraryBodyReadRuntimeFaultStaysClosedAndRestoresPosition(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(true);
        $body->method('getSize')->willReturn(null);
        $body->method('tell')->willReturn(7);
        $body->method('read')->willThrowException(new \RuntimeException('sensitive body diagnostic'));
        $body->expects(self::exactly(2))->method('seek')->with(7);
        $http = $this->allowingHttp();
        $sdk = $this->sdk($http, options: new Options(includeBody: true));
        $request = $this->request()->withBody($body);
        self::assertSame(FailureKind::Internal, $sdk->protect($request)->failureKind);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        self::assertSame(503, (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($request, $handler)->getStatusCode());
        self::assertCount(0, $http->requests);
    }

    public function testLoggerExceptionCannotChangeEstablishedFailureOrigin(): void
    {
        foreach ([FailureKind::Request, FailureKind::Internal, FailureKind::Service] as $kind) {
            $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
            $logger->expects(self::exactly(2))->method('error')->willThrowException(new NetworkFailure(new Request('POST', 'https://example.test/')));
            $http = $kind === FailureKind::Internal
                ? new FakeHttpClient(static function (): ResponseInterface { throw new \RuntimeException('private'); })
                : new FakeHttpClient(static fn (): ResponseInterface => new Response(503));
            $sdk = $this->sdk($http, logger: $logger);
            $request = $kind === FailureKind::Request ? $this->request('invalid') : $this->request();
            self::assertSame($kind, $sdk->protect($request)->failureKind);
            $handler = $this->createMock(RequestHandlerInterface::class);
            if ($kind === FailureKind::Service) {
                $handler->expects(self::once())->method('handle')->willReturn(new Response(204));
            } else {
                $handler->expects(self::never())->method('handle');
            }
            self::assertSame($kind === FailureKind::Service ? 204 : 503, (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($request, $handler)->getStatusCode());
        }
    }

    public function testNonerrorRemoteConclusionsNeverAcquireOperationalOriginFromErrorReason(): void
    {
        foreach ([Conclusion::Allow, Conclusion::Deny, Conclusion::Challenge] as $conclusion) {
            $proto = (new Proto\Decision())->setConclusion($conclusion->value)->setReason((new Proto\Reason())->setError(new Proto\ErrorReason()));
            $http = new FakeHttpClient(static fn (): ResponseInterface => self::response($proto));
            $sdk = $this->sdk($http);
            $decision = $sdk->protect($this->request());
            self::assertNull($decision->failureKind);
            self::assertFalse($decision->isFailOpenEligible());
            if ($conclusion !== Conclusion::Allow) {
                $handler = $this->createMock(RequestHandlerInterface::class);
                $handler->expects(self::never())->method('handle');
                self::assertSame(403, (new ArcjetMiddleware($sdk, new Psr17Factory(), failOpen: true))->process($this->request(), $handler)->getStatusCode());
            }
        }
    }
}
