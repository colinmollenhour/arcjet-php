<?php

declare(strict_types=1);

namespace Arcjet\Tests;

use Arcjet\Internal\Proto;
use Arcjet\Middleware\ArcjetMiddleware;
use Arcjet\Reason\ErrorReason;
use Arcjet\Tests\Support\FakeHttpClient;
use Arcjet\Tests\Support\SdkTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MetadataNormalizationTest extends SdkTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function uriHosts(): iterable
    {
        yield 'Latin1 host' => ["caf\xe9.test", 'café.test'];
        yield 'Latin1 host with port' => ["caf\xe9.test:8443", 'café.test:8443'];
        yield 'UTF8 host' => ['café.test', 'café.test'];
        yield 'UTF8 host with port' => ['café.test:8443', 'café.test:8443'];
        yield 'IPv6 host' => ['[2001:db8::1]', '[2001:db8::1]'];
        yield 'IPv6 host with port' => ['[2001:db8::1]:8443', '[2001:db8::1]:8443'];
    }

    #[DataProvider('uriHosts')]
    public function testUriHostNormalizationReachesRemoteDenyEvenWithFailOpen(string $authority, string $expected): void
    {
        $request = new ServerRequest('GET', 'https://' . $authority . '/', [], null, '1.1', ['REMOTE_ADDR' => '198.51.100.42']);
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response((new Proto\Decision())->setConclusion(Proto\Conclusion::CONCLUSION_DENY)));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $response = (new ArcjetMiddleware($this->sdk($http), new Psr17Factory(), failOpen: true))->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $http->requests);
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame($expected, $details->getHost());
        self::assertSame($expected, $details->getHeaders()['host']);
        self::assertSame($authority, $request->getHeaderLine('Host'));
    }

    public function testRealNyholmNumericHeaderNameReachesRemoteProtection(): void
    {
        $request = $this->request()->withHeader('123', 'test');
        self::assertArrayHasKey(123, $request->getHeaders());
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        self::assertCount(1, $http->requests);
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('test', $details->getHeaders()['123']);
    }

    public function testLatin1HeaderAndCookieValuesAreFaithfullyConvertedAndOriginalRequestUnchanged(): void
    {
        $request = $this->request()->withHeader('User-Agent', "caf\xe9")
            ->withHeader('Cookie', ["legacy=caf\xe9", "control=\x80\xff"]);
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        self::assertCount(1, $http->requests);
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('café', $details->getHeaders()['user-agent']);
        self::assertSame("legacy=café; control=\u{0080}ÿ", $details->getHeaders()['cookie']);
        self::assertSame("legacy=café; control=\u{0080}ÿ", $details->getCookies());
        self::assertSame("caf\xe9", $request->getHeaderLine('User-Agent'));
        self::assertSame(["legacy=caf\xe9", "control=\x80\xff"], $request->getHeader('Cookie'));
    }

    public function testValidUtf8ValuesAreNotDoubleEncodedIncludingMixedHeaderInstances(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/caf%C3%A9/%F0%9F%A4%96', [
            'User-Agent' => 'café 🤖', 'X-Mixed' => ['café', "caf\xe9"], 'Cookie' => 'name=café',
        ], null, '1.1', ['REMOTE_ADDR' => '198.51.100.42']);
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('/café/🤖', $details->getPath());
        self::assertSame('/caf%C3%A9/%F0%9F%A4%96', $details->getRawPath());
        self::assertSame('café 🤖', $details->getHeaders()['user-agent']);
        self::assertSame('café, café', $details->getHeaders()['x-mixed']);
        self::assertSame('name=café', $details->getCookies());
    }

    public function testInvalidPercentDecodedPathIsSubstitutedWhileRawEvidenceIsPreserved(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/%FF/%E2%82/%C3%A9', [], null, '1.1', ['REMOTE_ADDR' => '198.51.100.42']);
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        self::assertCount(1, $http->requests);
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame("/\u{FFFD}/\u{FFFD}/é", $details->getPath());
        self::assertSame('/%FF/%E2%82/%C3%A9', $details->getRawPath());
    }

    public function testCraftedMetadataCannotBypassRemoteDenyWithOperationalFailOpen(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/%FF', [
            '123' => 'test', 'User-Agent' => "caf\xe9", 'Cookie' => "legacy=caf\xe9",
        ], null, '1.1', ['REMOTE_ADDR' => '198.51.100.42']);
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response((new Proto\Decision())->setConclusion(Proto\Conclusion::CONCLUSION_DENY)));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $response = (new ArcjetMiddleware($this->sdk($http), new Psr17Factory(), failOpen: true))->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $http->requests);
    }

    public function testRawUriServerParameterHasPriorityOverRequestUriAndRebuiltPsrTarget(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/normalized%2Fpath?x=normalized', [], null, '1.1', [
            'REMOTE_ADDR' => '198.51.100.42',
            'RAW_URI' => '/raw%2fpath?signature=%2b&x=1&x=2',
            'REQUEST_URI' => '/request%2fpath?x=other',
        ]);
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('/raw%2fpath', $details->getRawPath());
        self::assertSame('?signature=%2b&x=1&x=2', $details->getQuery());
        self::assertSame('/normalized/path', $details->getPath());
    }

    public function testRequestUriServerParameterRetainsExactOriginalEncoding(): void
    {
        $request = new ServerRequest('GET', 'https://example.test/rebuilt%2Fpath?x=rebuilt', ['Raw-Uri' => '/visitor-header'], null, '1.1', [
            'REMOTE_ADDR' => '198.51.100.42', 'REQUEST_URI' => '/original%2fpath?signature=%2b&x=1&x=2',
        ]);
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('/original%2fpath', $details->getRawPath());
        self::assertSame('?signature=%2b&x=1&x=2', $details->getQuery());
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedRawTargets(): iterable
    {
        yield 'missing' => [null];
        yield 'not a string' => [['/unusable']];
        yield 'absolute form' => ['https://example.test/absolute'];
        yield 'fragment' => ['/unusable#fragment'];
        yield 'whitespace' => ['/unusable path'];
        yield 'non ascii' => ["/unusable\xff"];
        yield 'control' => ["/unusable\x01"];
    }

    #[DataProvider('malformedRawTargets')]
    public function testMalformedRawServerMetadataFallsBackToValidPsrTarget(mixed $target): void
    {
        $request = new ServerRequest('GET', 'https://example.test/fallback%2Fpath?x=raw%2b', [], null, '1.1', [
            'REMOTE_ADDR' => '198.51.100.42', 'RAW_URI' => $target, 'REQUEST_URI' => $target,
        ]);
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        self::assertCount(1, $http->requests);
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('/fallback%2Fpath', $details->getRawPath());
        self::assertSame('?x=raw%2b', $details->getQuery());
    }

    public function testInvalidPsrTargetFallsBackToValidUriWithoutRequestFailure(): void
    {
        $request = $this->request()->withRequestTarget('/invalid#fragment');
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('/a%2Fb', $details->getRawPath());
        self::assertSame('?x=a%2Bb&x=c', $details->getQuery());
    }

    public function testUtf8ExpansionStillHonorsWholePayloadCapBeforeNetwork(): void
    {
        $http = $this->allowingHttp();
        $request = $this->request()->withHeader('User-Agent', str_repeat("\xe9", 600000));
        $decision = $this->sdk($http)->protect($request);
        self::assertTrue($decision->isErrored());
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertSame('request_too_large', $decision->reason->code);
        self::assertCount(0, $http->requests);
    }
}
