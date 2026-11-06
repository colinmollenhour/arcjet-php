<?php

declare(strict_types=1);

namespace Arcjet\Tests;

use Arcjet\Decision;
use Arcjet\Internal\Proto;
use Arcjet\Middleware\ArcjetMiddleware;
use Arcjet\Tests\Support\FakeHttpClient;
use Arcjet\Tests\Support\SdkTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewareTest extends SdkTestCase
{
    /** @return iterable<string, array{int, bool, int, bool}> */
    public static function policies(): iterable
    {
        yield 'allow closed' => [1, false, 204, true];
        yield 'deny closed' => [2, false, 403, false];
        yield 'challenge closed' => [3, false, 403, false];
        yield 'error closed' => [4, false, 503, false];
        yield 'allow open' => [1, true, 204, true];
        yield 'deny open still blocks' => [2, true, 403, false];
        yield 'challenge open still blocks' => [3, true, 403, false];
        yield 'error open explicit' => [4, true, 204, true];
    }

    #[DataProvider('policies')]
    public function testMiddlewarePolicy(int $conclusion, bool $failOpen, int $status, bool $delegated): void
    {
        $reason = (new Proto\Reason())->setError((new Proto\ErrorReason())->setMessage('sensitive server diagnostic'));
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response((new Proto\Decision())->setConclusion($conclusion)->setReason($reason)));
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new Response(204);
            }
        };
        $request = $this->request();
        $response = (new ArcjetMiddleware($this->sdk($http), new Psr17Factory(), $failOpen))->process($request, $handler);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($delegated, $handler->request !== null);
        self::assertSame('', (string) $response->getBody());
        self::assertNull($request->getAttribute(ArcjetMiddleware::DECISION_ATTRIBUTE));
        if ($delegated) {
            self::assertNotNull($handler->request);
            $decision = $handler->request->getAttribute(ArcjetMiddleware::DECISION_ATTRIBUTE);
            self::assertInstanceOf(Decision::class, $decision);
            self::assertSame($conclusion, $decision->conclusion->value);
        } else {
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testTransportFailureIsClosedByDefault(): void
    {
        $http = new FakeHttpClient(static fn (): ResponseInterface => new Response(503));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $response = (new ArcjetMiddleware($this->sdk($http), new Psr17Factory()))->process($this->request(), $handler);
        self::assertSame(503, $response->getStatusCode());
    }
}
