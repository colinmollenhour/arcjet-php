<?php

declare(strict_types=1);

namespace Arcjet\Tests;

use Arcjet\Internal\Proto;
use Arcjet\Options;
use Arcjet\Reason\ErrorReason;
use Arcjet\Rule\BotProtection;
use Arcjet\Tests\Support\SdkTestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;

final class RequestNormalizationTest extends SdkTestCase
{
    public function testRequestComponentsHeaderFoldingCookiesAndAuthorizationRedaction(): void
    {
        $request = new ServerRequest('POST', 'https://example.test:8443/a%2Fb/%2520?x=a%2Bb&x=c', [
            'Authorization' => 'Bearer inbound-secret', 'Proxy-Authorization' => 'Basic private',
            'Cookie' => ['a=1', 'b=2'], 'X-Multi' => ['one', 'two'], 'X-Private' => 'hidden',
        ], 'unread body', '2', ['REMOTE_ADDR' => '198.51.100.9']);
        $http = $this->allowingHttp();
        $this->sdk($http, options: new Options(redactHeaders: ['X-Private']))->protect($request, correlationId: 'request-123');
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('POST', $details->getMethod());
        self::assertSame('https', $details->getProtocol());
        self::assertSame('example.test:8443', $details->getHost());
        self::assertSame('/a/b/%20', $details->getPath());
        self::assertSame('/a%2Fb/%2520', $details->getRawPath());
        self::assertSame('?x=a%2Bb&x=c', $details->getQuery());
        self::assertSame('a=1; b=2', $details->getCookies());
        self::assertSame('a=1; b=2', $details->getHeaders()['cookie']);
        self::assertSame('one, two', $details->getHeaders()['x-multi']);
        self::assertFalse(isset($details->getHeaders()['authorization']));
        self::assertFalse(isset($details->getHeaders()['proxy-authorization']));
        self::assertFalse(isset($details->getHeaders()['x-private']));
        self::assertSame('request-123', $details->getCorrelationId());
        self::assertSame('', $details->getBody());
        self::assertSame('Bearer inbound-secret', $request->getHeaderLine('Authorization'));
        self::assertSame(0, $request->getBody()->tell());
    }

    public function testRedactingCookiesAlsoRemovesDedicatedCookiesField(): void
    {
        $http = $this->allowingHttp();
        $this->sdk($http, options: new Options(redactHeaders: ['COOKIE']))->protect($this->request()->withHeader('Cookie', 'private=secret'));
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('', $details->getCookies());
        self::assertFalse(isset($details->getHeaders()['cookie']));
    }

    public function testEmptyUriPathAndQueryAreRepresentedCorrectly(): void
    {
        $http = $this->allowingHttp();
        $this->sdk($http)->protect(new ServerRequest('GET', 'https://[2001:db8::1]:8443', [], null, '1.1', ['REMOTE_ADDR' => '::1']));
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('[2001:db8::1]:8443', $details->getHost());
        self::assertSame('/', $details->getPath());
        self::assertSame('/', $details->getRawPath());
        self::assertSame('', $details->getQuery());
    }

    public function testOriginalOriginFormRequestTargetPreservesEscapingAndQuery(): void
    {
        $http = $this->allowingHttp();
        $request = $this->request()->withRequestTarget('/original%2fpath?signature=%2B&x=1&x=2');
        self::assertTrue($this->sdk($http)->protect($request)->isAllowed());
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('/original%2fpath', $details->getRawPath());
        self::assertSame('?signature=%2B&x=1&x=2', $details->getQuery());
        self::assertSame('/a/b', $details->getPath());
    }

    /** @return iterable<string, array{string, list<string>, string, string}> */
    public static function proxyCases(): iterable
    {
        yield 'untrusted direct peer ignores spoofed xff' => ['198.51.100.42', [], '1.1.1.1', '198.51.100.42'];
        yield 'configured but unmatched proxy' => ['198.51.100.42', ['10.0.0.0/8'], '1.1.1.1', '198.51.100.42'];
        yield 'single trusted proxy' => ['10.0.0.1', ['10.0.0.1'], '203.0.113.42', '203.0.113.42'];
        yield 'leftmost spoof never selected' => ['10.0.0.1', ['10.0.0.0/8'], '1.1.1.1, 203.0.113.42', '203.0.113.42'];
        yield 'trusted chain removed' => ['10.0.0.1', ['10.0.0.0/8'], '1.1.1.1, 203.0.113.42, 10.0.0.2', '203.0.113.42'];
        yield 'multiple trusted cidrs' => ['10.0.0.1', ['10.0.0.0/8', '192.168.0.0/16'], '203.0.113.42, 192.168.1.2', '203.0.113.42'];
        yield 'ipv6 trusted proxy' => ['2001:db8:1::a', ['2001:db8:1::/48'], '2001:db8:2::b', '2001:db8:2::b'];
        yield 'ipv6 untrusted proxy' => ['2001:db8:2::a', ['2001:db8:1::/48'], '::1', '2001:db8:2::a'];
        yield 'ipv4 bitwise prefix inside' => ['192.0.2.127', ['192.0.2.0/25'], '203.0.113.1', '203.0.113.1'];
        yield 'ipv4 bitwise prefix outside' => ['192.0.2.128', ['192.0.2.0/25'], '203.0.113.1', '192.0.2.128'];
        yield 'ipv6 bitwise prefix inside' => ['2001:db8:0:0:7fff::', ['2001:db8::/65'], '203.0.113.1', '203.0.113.1'];
        yield 'ipv6 bitwise prefix outside' => ['2001:db8:0:0:8000::', ['2001:db8::/65'], '203.0.113.1', '2001:db8:0:0:8000::'];
        yield 'families never mixed' => ['::ffff:192.0.2.1', ['192.0.2.0/24'], '203.0.113.1', '::ffff:192.0.2.1'];
        yield 'full ipv6 prefix' => ['2001:db8::1', ['2001:db8::1/128'], '203.0.113.1', '203.0.113.1'];
        yield 'zero prefix deliberate global trust' => ['192.0.2.1', ['0.0.0.0/0'], '203.0.113.1', '203.0.113.1'];
        yield 'malformed spoof before first untrusted hop ignored' => ['10.0.0.1', ['10.0.0.0/8'], 'unknown, 203.0.113.42', '203.0.113.42'];
    }

    /** @param list<string> $trusted */
    #[DataProvider('proxyCases')]
    public function testTrustedProxyIpSelection(string $peer, array $trusted, string $xff, string $expected): void
    {
        $http = $this->allowingHttp();
        $decision = $this->sdk($http, options: new Options(trustedProxies: $trusted))->protect($this->request($peer)->withHeader('X-Forwarded-For', $xff));
        self::assertTrue($decision->isAllowed());
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame($expected, $details->getIp());
        self::assertSame('true', $details->getExtra()['arcjet_disable_automatic_ip_detection']);
    }

    public function testRepeatedXffUsesOriginalOrderAndVendorHeadersCannotOverride(): void
    {
        $http = $this->allowingHttp();
        $request = $this->request('10.0.0.1')->withHeader('X-Forwarded-For', ['1.1.1.1', '203.0.113.42, 10.0.0.2'])
            ->withHeader('Forwarded', 'for=8.8.8.8')->withHeader('CF-Connecting-IP', '8.8.4.4');
        $this->sdk($http, options: new Options(trustedProxies: ['10.0.0.0/8']))->protect($request);
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('203.0.113.42', $details->getIp());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidXff(): iterable
    {
        yield 'empty' => [''];
        yield 'invalid' => ['unknown'];
        yield 'port forbidden' => ['203.0.113.1:1234'];
        yield 'bracketed ipv6 forbidden' => ['[2001:db8::1]'];
        yield 'empty hop' => ['203.0.113.1,'];
        yield 'too many hops' => [implode(',', array_fill(0, 65, '10.0.0.2'))];
        yield 'too long' => [str_repeat('1', 8193)];
    }

    #[DataProvider('invalidXff')]
    public function testMalformedTrustedForwardingProducesErrorWithoutNetwork(string $xff): void
    {
        $http = $this->allowingHttp();
        $decision = $this->sdk($http, options: new Options(trustedProxies: ['10.0.0.1']))->protect($this->request('10.0.0.1')->withHeader('X-Forwarded-For', $xff));
        self::assertTrue($decision->isErrored());
        self::assertCount(0, $http->requests);
    }

    public function testMissingOrInvalidPeerCannotFallBackToForwardingHeaders(): void
    {
        foreach ([[], ['REMOTE_ADDR' => 'invalid'], ['REMOTE_ADDR' => ['127.0.0.1']]] as $params) {
            $http = $this->allowingHttp();
            $request = new ServerRequest('GET', 'https://example.test/', ['X-Forwarded-For' => '1.1.1.1'], null, '1.1', $params);
            self::assertTrue($this->sdk($http)->protect($request)->isErrored());
            self::assertCount(0, $http->requests);
        }
    }

    public function testApplicationOverrideWorksWithoutPeerAndDisablesAutomaticDetection(): void
    {
        $http = $this->allowingHttp();
        $request = new ServerRequest('GET', 'https://example.test/', ['X-Forwarded-For' => '1.1.1.1']);
        self::assertTrue($this->sdk($http)->protect($request, ip: '2001:db8::7')->isAllowed());
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame('2001:db8::7', $details->getIp());
        self::assertSame('true', $details->getExtra()['arcjet_disable_automatic_ip_detection']);
    }

    public function testInvalidOverrideAndCorrelationIdProduceErrorsWithoutNetwork(): void
    {
        $http = $this->allowingHttp();
        $sdk = $this->sdk($http);
        self::assertTrue($sdk->protect($this->request(), ip: 'not an IP')->isErrored());
        foreach (['', "a\nb", str_repeat('a', 257), 'é'] as $id) {
            self::assertTrue($sdk->protect($this->request(), correlationId: $id)->isErrored());
        }
        self::assertCount(0, $http->requests);
    }

    public function testBodyIsNeverTouchedByDefaultEvenWhenUnseekable(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->expects(self::never())->method('read');
        $body->expects(self::never())->method('tell');
        $body->expects(self::never())->method('isSeekable');
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http)->protect($this->request()->withBody($body))->isAllowed());
    }

    public function testOptInReadsExactBodyFromStartAndRestoresPointerForSignedBots(): void
    {
        $body = Stream::create("binary\x00body\xff");
        $body->seek(3);
        $request = $this->request()->withBody($body)->withHeader('Signature-Input', 'sig=("@method")')->withHeader('Signature', 'sig=:abc:')->withHeader('Signature-Agent', '"https://agent.example.test"');
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http, [new BotProtection(allow: ['CHATGPT_AGENT'])], new Options(includeBody: true))->protect($request)->isAllowed());
        self::assertSame(3, $body->tell());
        $details = self::decodeRequest($http->requests[0])->getDetails();
        self::assertNotNull($details);
        self::assertSame("binary\x00body\xff", $details->getBody());
        self::assertSame('sig=:abc:', $details->getHeaders()['signature']);
        self::assertSame('sig=("@method")', $details->getHeaders()['signature-input']);
        self::assertSame('"https://agent.example.test"', $details->getHeaders()['signature-agent']);
    }

    public function testOversizedBodyRestoresPointerAndNeverCallsNetwork(): void
    {
        $body = Stream::create('too large');
        $body->seek(4);
        $http = $this->allowingHttp();
        $decision = $this->sdk($http, options: new Options(includeBody: true, maxBodyBytes: 3))->protect($this->request()->withBody($body));
        self::assertTrue($decision->isErrored());
        self::assertSame(4, $body->tell());
        self::assertCount(0, $http->requests);
    }

    public function testUnseekableBodyOptInIsErrorWithoutConsuming(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('isSeekable')->willReturn(false);
        $body->expects(self::never())->method('read');
        $http = $this->allowingHttp();
        self::assertTrue($this->sdk($http, options: new Options(includeBody: true))->protect($this->request()->withBody($body))->isErrored());
        self::assertCount(0, $http->requests);
    }

    public function testEntireProtobufSizeLimitPreventsNetworkForHugeUriOrHeaders(): void
    {
        foreach ([$this->request()->withUri(new \Nyholm\Psr7\Uri('https://example.test/?q=' . str_repeat('a', 1048576))), $this->request()->withHeader('X-Large', str_repeat('a', 1048576))] as $request) {
            $http = $this->allowingHttp();
            $decision = $this->sdk($http)->protect($request);
            self::assertTrue($decision->isErrored());
            self::assertInstanceOf(ErrorReason::class, $decision->reason);
            self::assertSame('request_too_large', $decision->reason->code);
            self::assertCount(0, $http->requests);
        }
    }

    public function testAccidentallyReflectedSdkKeyDoesNotLeaveThroughMetadata(): void
    {
        $http = $this->allowingHttp();
        $decision = $this->sdk($http)->protect($this->request()->withHeader('X-Accidental-Key', self::KEY));
        self::assertTrue($decision->isErrored());
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertSame('secret_in_request', $decision->reason->code);
        self::assertCount(0, $http->requests);
    }
}
