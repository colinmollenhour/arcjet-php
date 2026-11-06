<?php

declare(strict_types=1);

namespace Arcjet\Tests\Protocol;

use Arcjet\Internal\Proto\BotV2Rule;
use Arcjet\Internal\Proto\Conclusion;
use Arcjet\Internal\Proto\DecideRequest;
use Arcjet\Internal\Proto\DecideResponse;
use Arcjet\Internal\Proto\Mode;
use Arcjet\Internal\Proto\PromptInjectionDetectionRule;
use Arcjet\Internal\Proto\Reason;
use Arcjet\Internal\Proto\Rule;
use Arcjet\Internal\Proto\RuleState;
use Arcjet\Internal\Proto\ShieldReason;
use Arcjet\Internal\Proto\ShieldRule;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratedProtocolTest extends TestCase
{
    /** @return iterable<string, array{string, class-string<DecideRequest>|class-string<DecideResponse>}> */
    public static function pythonFixtures(): iterable
    {
        foreach (['request-dry_run-allow', 'request-dry_run-deny', 'request-live-allow', 'request-live-deny'] as $name) {
            yield $name => [$name, DecideRequest::class];
        }
        foreach (['response-bot-deny-ip', 'response-shield-deny', 'response-dry-run-allow', 'response-challenge', 'response-error', 'response-timestamp', 'response-absent-decision', 'response-unknown-conclusion'] as $name) {
            yield $name => [$name, DecideResponse::class];
        }
    }

    /** @param class-string<DecideRequest>|class-string<DecideResponse> $class */
    #[DataProvider('pythonFixtures')]
    public function testPythonWireParsesAndReserializesWithoutSemanticDrift(string $name, string $class): void
    {
        $message = new $class();
        $message->mergeFromString(self::fixture($name . '.bin'));
        $expected = self::expectation($name);
        self::assertEquals($expected, self::fixtureSemanticValue($name, $message));

        $roundTrip = new $class();
        $roundTrip->mergeFromString($message->serializeToString());
        self::assertEquals($expected, self::fixtureSemanticValue($name, $roundTrip));

        // Map ordering is unspecified. Map-free responses also have exact wire equality.
        if ($message instanceof DecideResponse && $name !== 'response-bot-deny-ip') {
            self::assertSame(self::fixture($name . '.bin'), $message->serializeToString());
            self::assertSame(self::fixture($name . '.bin'), $roundTrip->serializeToString());
        }
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function requestModes(): iterable
    {
        yield 'dry-run allow' => ['request-dry_run-allow', Mode::MODE_DRY_RUN, true];
        yield 'dry-run deny' => ['request-dry_run-deny', Mode::MODE_DRY_RUN, false];
        yield 'live allow' => ['request-live-allow', Mode::MODE_LIVE, true];
        yield 'live deny' => ['request-live-deny', Mode::MODE_LIVE, false];
    }

    #[DataProvider('requestModes')]
    public function testPhpRequestSettersMatchPythonFixture(string $name, int $mode, bool $allow): void
    {
        $message = FixtureMessages::request($mode, $allow);
        self::assertEncodedMessageMatchesPython($name, $message, new DecideRequest());
        self::assertSame('shield', $message->getRules()[0]->getRule());
        self::assertSame('bot_v2', $message->getRules()[1]->getRule());
        self::assertSame("\0snowman: ☃\xff", $message->getDetails()?->getBody());
    }

    public function testPhpResponseSettersMatchBotShieldAndIpFixture(): void
    {
        self::assertEncodedMessageMatchesPython('response-bot-deny-ip', FixtureMessages::botResponse(), new DecideResponse());
    }

    public function testPresenceAndDryRunAggregateSurviveTheWire(): void
    {
        $missing = new DecideResponse();
        $missing->mergeFromString(self::fixture('response-absent-decision.bin'));
        self::assertFalse($missing->hasDecision());
        self::assertNull($missing->getDecision());

        $response = new DecideResponse();
        $response->mergeFromString(self::fixture('response-dry-run-allow.bin'));
        $decision = $response->getDecision();
        self::assertNotNull($decision);
        self::assertSame(Conclusion::CONCLUSION_ALLOW, $decision->getConclusion());
        self::assertFalse($decision->hasIpDetails());
        self::assertNull($decision->getIpDetails());
        self::assertSame(Conclusion::CONCLUSION_DENY, $decision->getRuleResults()[0]->getConclusion());
        self::assertSame(RuleState::RULE_STATE_DRY_RUN, $decision->getRuleResults()[0]->getState());
    }

    public function testTimestampDependencyUsesOfficialGoogleRuntime(): void
    {
        $response = new DecideResponse();
        $response->mergeFromString(self::fixture('response-timestamp.bin'));
        $reset = $response->getDecision()?->getReason()?->getRateLimit()?->getResetTime();
        self::assertInstanceOf(Timestamp::class, $reset);
        self::assertSame(1793968496, (int) $reset->getSeconds());
        self::assertSame(123456789, $reset->getNanos());
    }

    public function testOneofsReplaceEarlierAlternatives(): void
    {
        $rule = (new Rule())->setShield(new ShieldRule());
        self::assertTrue($rule->hasShield());
        $rule->setBotV2(new BotV2Rule());
        self::assertSame('bot_v2', $rule->getRule());
        self::assertFalse($rule->hasShield());
        self::assertNull($rule->getShield());

        $reason = (new Reason())->setShield(new ShieldReason());
        self::assertSame('shield', $reason->getReason());
    }

    public function testProto3OptionalScalarPreservesExplicitZero(): void
    {
        $rule = new PromptInjectionDetectionRule();
        self::assertFalse($rule->hasThreshold());
        $rule->setThreshold(0.0);
        $parsed = new PromptInjectionDetectionRule();
        $parsed->mergeFromString($rule->serializeToString());
        self::assertTrue($parsed->hasThreshold());
        self::assertSame(0.0, $parsed->getThreshold());
    }

    public function testOriginalDescriptorAndTimestampArePinnedOffline(): void
    {
        $resources = dirname(__DIR__, 2) . '/resources/proto/';
        self::assertSame('d9235e410d6b87a099da2bffb324186eefce4d7a007352a520040968cca06d6e', hash_file('sha256', $resources . 'decide.original.bin'));
        self::assertSame('422a163a2d7051465e0a516584b4d96d18c270ec4e7ad3c21ad33e5c440ebecc', hash_file('sha256', $resources . 'timestamp.bin'));
        self::assertSame('498b28b17ebc1a652974ab8ae98c60604e892095', self::provenanceRevision());
    }

    private static function assertEncodedMessageMatchesPython(string $name, Message $message, Message $parsed): void
    {
        $parsed->mergeFromString($message->serializeToString());
        self::assertEquals(self::expectation($name), json_decode($parsed->serializeToJsonString(), false, 512, JSON_THROW_ON_ERROR));
    }

    private static function fixtureSemanticValue(string $name, Message $message): mixed
    {
        if ($name !== 'response-timestamp') {
            return json_decode($message->serializeToJsonString(), false, 512, JSON_THROW_ON_ERROR);
        }

        // The dependency's Timestamp JSON serializer requires bcmath; binary protobuf
        // does not. Project this fixture from getters on every runtime, so minimal
        // PHP installations retain the full Python JSON and exact binary assertions.
        self::assertInstanceOf(DecideResponse::class, $message);
        $decision = $message->getDecision();
        self::assertNotNull($decision);
        $results = [];
        foreach ($decision->getRuleResults() as $result) {
            $results[] = (object) [
                'ruleId' => $result->getRuleId(),
                'state' => RuleState::name($result->getState()),
                'conclusion' => Conclusion::name($result->getConclusion()),
                'reason' => self::timestampReasonValue($result->getReason()),
            ];
        }

        return (object) ['decision' => (object) [
            'id' => $decision->getId(),
            'conclusion' => Conclusion::name($decision->getConclusion()),
            'reason' => self::timestampReasonValue($decision->getReason()),
            'ruleResults' => $results,
        ]];
    }

    private static function timestampReasonValue(?Reason $reason): \stdClass
    {
        self::assertNotNull($reason);
        $rateLimit = $reason->getRateLimit();
        self::assertNotNull($rateLimit);
        $timestamp = $rateLimit->getResetTime();
        self::assertInstanceOf(Timestamp::class, $timestamp);

        return (object) ['rateLimit' => (object) [
            'max' => $rateLimit->getMax(),
            'count' => $rateLimit->getCount(),
            'resetInSeconds' => $rateLimit->getResetInSeconds(),
            'resetTime' => gmdate('Y-m-d\TH:i:s', (int) $timestamp->getSeconds())
                . sprintf('.%09dZ', $timestamp->getNanos()),
            'windowInSeconds' => $rateLimit->getWindowInSeconds(),
        ]];
    }

    private static function fixture(string $name): string
    {
        $bytes = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertNotFalse($bytes);

        return $bytes;
    }

    private static function expectation(string $name): mixed
    {
        $expectation = json_decode(self::fixture($name . '.json'), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $expectation);
        self::assertTrue(property_exists($expectation, 'value'));

        return $expectation->value;
    }

    private static function provenanceRevision(): string
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/resources/proto/provenance.json');
        self::assertNotFalse($json);
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $value);
        self::assertTrue(property_exists($value, 'revision'));
        self::assertIsString($value->revision);

        return $value->revision;
    }
}
