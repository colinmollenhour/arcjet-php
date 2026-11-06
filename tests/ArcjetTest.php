<?php

declare(strict_types=1);

namespace Arcjet\Tests;

use Arcjet\Arcjet;
use Arcjet\Conclusion;
use Arcjet\Internal\Proto;
use Arcjet\Mode;
use Arcjet\Options;
use Arcjet\Reason\BotReason;
use Arcjet\Reason\ErrorReason;
use Arcjet\Reason\GenericReason;
use Arcjet\Reason\ShieldReason;
use Arcjet\Rule\BotProtection;
use Arcjet\Rule\Shield;
use Arcjet\RuleState;
use Arcjet\Tests\Support\FakeHttpClient;
use Arcjet\Tests\Support\SdkTestCase;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

final class ArcjetTest extends SdkTestCase
{
    public function testConnectRequestUsesRealProtobufAndHonestSdkIdentity(): void
    {
        $http = $this->allowingHttp();
        $decision = $this->sdk($http, [new Shield(Mode::Live, ['ip.src']), new BotProtection(allow: ['CATEGORY:SEARCH_ENGINE', 'CURL'])])->protect($this->request());
        self::assertTrue($decision->isAllowed());
        self::assertFalse($decision->isDenied());
        self::assertFalse($decision->isErrored());
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://decide.arcjet.com/proto.decide.v1alpha1.DecideService/Decide', (string) $request->getUri());
        self::assertSame('application/proto', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/proto', $request->getHeaderLine('Accept'));
        self::assertSame('1', $request->getHeaderLine('Connect-Protocol-Version'));
        self::assertSame('500', $request->getHeaderLine('Connect-Timeout-Ms'));
        self::assertSame('Bearer ' . self::KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('arcjet-php/' . Arcjet::VERSION, $request->getHeaderLine('User-Agent'));
        self::assertFalse($request->hasHeader('Accept-Encoding'));
        $proto = self::decodeRequest($request);
        self::assertSame(Proto\SDKStack::SDK_STACK_UNSPECIFIED, $proto->getSdkStack());
        self::assertSame(Arcjet::VERSION, $proto->getSdkVersion());
        self::assertCount(2, $proto->getRules());
        $shield = $proto->getRules()[0]->getShield();
        self::assertNotNull($shield);
        self::assertSame(Proto\Mode::MODE_LIVE, $shield->getMode());
        self::assertSame(['ip.src'], iterator_to_array($shield->getCharacteristics()));
        $bot = $proto->getRules()[1]->getBotV2();
        self::assertNotNull($bot);
        self::assertSame(Proto\Mode::MODE_DRY_RUN, $bot->getMode());
        self::assertSame(['CATEGORY:SEARCH_ENGINE', 'CURL'], iterator_to_array($bot->getAllow()));
        self::assertCount(0, $bot->getDeny());
    }

    public function testEmptyAllowAndNonemptyDenySerialization(): void
    {
        $http = $this->allowingHttp();
        $this->sdk($http, [new BotProtection(allow: []), new BotProtection(deny: ['CURL'], mode: Mode::Live)])->protect($this->request());
        $rules = self::decodeRequest($http->requests[0])->getRules();
        self::assertSame('bot_v2', $rules[0]->getRule());
        $allow = $rules[0]->getBotV2();
        self::assertNotNull($allow);
        self::assertCount(0, $allow->getAllow());
        $deny = $rules[1]->getBotV2();
        self::assertNotNull($deny);
        self::assertSame(['CURL'], iterator_to_array($deny->getDeny()));
    }

    public function testWithRuleIsIndependentAndNoCachingOrReportingOccurs(): void
    {
        $http = $this->allowingHttp();
        $base = $this->sdk($http);
        $child = $base->withRule(new Shield());
        $grandchild = $child->withRule(new BotProtection(allow: []));
        foreach ([$base, $child, $grandchild, $base] as $sdk) {
            self::assertTrue($sdk->protect($this->request())->isAllowed());
        }
        self::assertCount(4, $http->requests);
        $counts = array_map(static fn ($request): int => count(self::decodeRequest($request)->getRules()), $http->requests);
        self::assertSame([0, 1, 2, 0], $counts);
    }

    public function testCustomEndpointAndDeadline(): void
    {
        $http = $this->allowingHttp();
        $this->sdk($http, options: new Options(baseUrl: 'https://decide.example.test:8443/base/', deadlineMs: 1234))->protect($this->request());
        self::assertSame('https://decide.example.test:8443/base/proto.decide.v1alpha1.DecideService/Decide', (string) $http->requests[0]->getUri());
        self::assertSame('1234', $http->requests[0]->getHeaderLine('Connect-Timeout-Ms'));
    }

    /** @return iterable<string, array{int, bool, bool, bool, bool}> */
    public static function conclusions(): iterable
    {
        yield 'allow' => [1, true, false, false, false];
        yield 'deny' => [2, false, true, false, false];
        yield 'challenge' => [3, false, false, true, false];
        yield 'error' => [4, false, false, false, true];
    }

    #[DataProvider('conclusions')]
    public function testConclusionSemantics(int $code, bool $allowed, bool $denied, bool $challenged, bool $errored): void
    {
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response((new Proto\Decision())->setConclusion($code)));
        $decision = $this->sdk($http)->protect($this->request());
        self::assertSame($allowed, $decision->isAllowed());
        self::assertSame($denied, $decision->isDenied());
        self::assertSame($challenged, $decision->isChallenged());
        self::assertSame($errored, $decision->isErrored());
    }

    public function testBotShieldReasonsAndDryRunDoNotOverrideAggregate(): void
    {
        $bot = (new Proto\BotV2Reason())->setAllowed(['GOOGLE_CRAWLER'])->setDenied(['CURL'])->setVerified(true)->setSpoofed(true);
        $shield = (new Proto\ShieldReason())->setShieldTriggered(true)->setSuspicious(true);
        $proto = (new Proto\Decision())->setId('dryrun')->setConclusion(1)->setTtl(12)->setReason((new Proto\Reason())->setShield($shield))
            ->setRuleResults([
                (new Proto\RuleResult())->setRuleId('bot-rule')->setState(3)->setConclusion(2)->setReason((new Proto\Reason())->setBotV2($bot))->setFingerprint('fingerprint')->setTtl(7),
                (new Proto\RuleResult())->setState(2),
            ]);
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response($proto));
        $decision = $this->sdk($http)->protect($this->request());
        self::assertTrue($decision->isAllowed());
        self::assertSame(12, $decision->ttl);
        self::assertInstanceOf(ShieldReason::class, $decision->reason);
        self::assertTrue($decision->reason->shieldTriggered);
        self::assertTrue($decision->reason->suspicious);
        self::assertTrue($decision->reason->isShield());
        self::assertFalse($decision->reason->isBot());
        $result = $decision->results[0];
        self::assertSame('bot-rule', $result->ruleId);
        self::assertSame(RuleState::DryRun, $result->state);
        self::assertSame(7, $result->ttl);
        self::assertSame('fingerprint', $result->fingerprint);
        self::assertTrue($result->isDenied());
        self::assertFalse($result->isAllowed());
        self::assertFalse($result->isChallenged());
        self::assertFalse($result->isErrored());
        self::assertInstanceOf(BotReason::class, $result->reason);
        self::assertSame(['GOOGLE_CRAWLER'], $result->reason->allowed);
        self::assertSame(['CURL'], $result->reason->denied);
        self::assertTrue($result->reason->verified);
        self::assertTrue($result->reason->spoofed);
        self::assertTrue($result->reason->isBot());
        self::assertSame(Conclusion::Unspecified, $decision->results[1]->conclusion);
    }

    public function testUnknownReasonsPreserveOpaqueFieldsAndAuthoritativeDeny(): void
    {
        $reason = new Proto\Reason();
        // Unknown length-delimited field 99, three raw bytes: forward-compatible reason.
        $bytes = "\x9a\x06\x03new";
        $reason->mergeFromString($bytes);
        $proto = (new Proto\Decision())->setConclusion(2)->setReason($reason);
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response($proto));
        $decision = $this->sdk($http)->protect($this->request());
        self::assertTrue($decision->isDenied());
        self::assertInstanceOf(GenericReason::class, $decision->reason);
        self::assertSame('UNKNOWN', $decision->reason->type);
        self::assertSame($bytes, $decision->reason->protobuf);
    }

    public function testKnownButUnsupportedReasonIsNotMisrepresentedAsError(): void
    {
        $reason = (new Proto\Reason())->setEdgeRule(new Proto\EdgeRuleReason());
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response((new Proto\Decision())->setConclusion(2)->setReason($reason)));
        $decision = $this->sdk($http)->protect($this->request());
        self::assertTrue($decision->isDenied());
        self::assertInstanceOf(GenericReason::class, $decision->reason);
        self::assertSame('edge_rule', $decision->reason->type);
    }

    /** @return iterable<string, array{int, string, string, string}> */
    public static function badResponses(): iterable
    {
        yield '401 json' => [401, 'application/json', '{"code":"unauthenticated"}', 'http_status'];
        yield 'redirect' => [302, 'text/html', '', 'http_status'];
        yield '503' => [503, 'application/proto', '', 'http_status'];
        yield 'html' => [200, 'text/html', '<html>', 'content_type'];
        yield 'missing type' => [200, '', '', 'content_type'];
        yield 'empty response' => [200, 'application/proto', '', 'missing_decision'];
        yield 'missing decision' => [200, 'application/proto', "\x12\x00", 'missing_decision'];
        yield 'invalid wire' => [200, 'application/proto', "\xff", 'malformed_response'];
        yield 'truncated wire' => [200, 'application/proto', "\x0a\x08\x01", 'malformed_response'];
        yield 'default decision' => [200, 'application/proto', "\x0a\x00", 'invalid_conclusion'];
        yield 'unknown conclusion' => [200, 'application/proto', "\x0a\x02\x10\x63", 'invalid_conclusion'];
        yield 'allow plus malformed tail' => [200, 'application/proto', "\x0a\x02\x10\x01\xff", 'malformed_response'];
        yield 'nested allow plus malformed tail' => [200, 'application/proto', "\x0a\x03\x10\x01\xff", 'malformed_response'];
        yield 'allow plus zero tag' => [200, 'application/proto', "\x0a\x02\x10\x01\x00", 'malformed_response'];
        yield 'truncated unknown fixed64' => [200, 'application/proto', "\x0a\x02\x10\x01\x19x", 'malformed_response'];
        yield 'overlong scalar varint' => [200, 'application/proto', "\x0a\x02\x10\x01\x18" . str_repeat("\xff", 10), 'malformed_response'];
        yield 'overlong tag varint' => [200, 'application/proto', str_repeat("\xff", 5), 'malformed_response'];
        yield 'unsupported wire type' => [200, 'application/proto', "\x0a\x02\x10\x01\x1f", 'malformed_response'];
    }

    #[DataProvider('badResponses')]
    public function testBadResponsesBecomeExplicitErrors(int $status, string $type, string $body, string $code): void
    {
        $http = new FakeHttpClient(static fn (): ResponseInterface => new Response($status, ['Content-Type' => $type], $body));
        $decision = $this->sdk($http)->protect($this->request());
        self::assertTrue($decision->isErrored());
        self::assertFalse($decision->isAllowed());
        self::assertFalse($decision->isDenied());
        self::assertSame('', $decision->id);
        self::assertNull($decision->ip);
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertTrue($decision->reason->isError());
        self::assertSame($code, $decision->reason->code);
    }

    public function testResponseBoundsAndEncoding(): void
    {
        foreach ([new Response(200, ['Content-Type' => 'application/proto'], str_repeat('a', 20)), new Response(200, ['Content-Type' => 'application/proto', 'Content-Encoding' => 'gzip'], 'gzip')] as $response) {
            $http = new FakeHttpClient(static fn (): ResponseInterface => $response);
            self::assertTrue($this->sdk($http, options: new Options(maxResponseBytes: 10))->protect($this->request())->isErrored());
        }
    }

    public function testSensitiveTransportExceptionsAndPayloadsNeverReachLogsOrSyntheticReason(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $entries = [];
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = (string) $message . json_encode($context, JSON_THROW_ON_ERROR);
            }
        };
        $secretMessage = self::KEY . ' Authorization: Bearer customer-secret; Cookie=session=private';
        $http = new FakeHttpClient(static function () use ($secretMessage): ResponseInterface {
            throw new class($secretMessage) extends \RuntimeException implements ClientExceptionInterface {};
        });
        $sdk = $this->sdk($http, logger: $logger);
        $decision = $sdk->protect($this->request());
        self::assertTrue($decision->isErrored());
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertStringNotContainsString(self::KEY, $decision->reason->message);
        self::assertStringNotContainsString('customer-secret', implode('', $logger->entries));
        self::assertStringNotContainsString(self::KEY, implode('', $logger->entries));
        ob_start();
        var_dump($sdk);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString(self::KEY, $debug);
    }

    public function testRemoteErrorMessageIsPreservedButNeverLogged(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::never())->method('log');
        $logger->expects(self::never())->method('error');
        $reason = (new Proto\Reason())->setError((new Proto\ErrorReason())->setMessage('service detail'));
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response((new Proto\Decision())->setConclusion(4)->setReason($reason)));
        $decision = $this->sdk($http, logger: $logger)->protect($this->request());
        self::assertInstanceOf(ErrorReason::class, $decision->reason);
        self::assertSame('service detail', $decision->reason->message);
    }

    public function testLoggerFailureDoesNotEscapeProtection(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willThrowException(new \RuntimeException('logger failure'));
        $http = new FakeHttpClient(static fn (): ResponseInterface => new Response(503));
        self::assertTrue($this->sdk($http, logger: $logger)->protect($this->request())->isErrored());
    }
}
