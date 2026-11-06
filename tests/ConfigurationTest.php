<?php

declare(strict_types=1);

namespace Arcjet\Tests;

use Arcjet\Arcjet;
use Arcjet\Mode;
use Arcjet\Options;
use Arcjet\Rule\BotProtection;
use Arcjet\Rule\Shield;
use Arcjet\Tests\Support\SdkTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfigurationTest extends SdkTestCase
{
    /** @return iterable<string, array{class-string, array<string, mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'bot neither list' => [BotProtection::class, []];
        yield 'bot both lists' => [BotProtection::class, ['allow' => [], 'deny' => ['CURL']]];
        yield 'empty deny ambiguous' => [BotProtection::class, ['deny' => []]];
        yield 'empty bot id' => [BotProtection::class, ['allow' => ['']]];
        yield 'bad bot id' => [BotProtection::class, ['allow' => ['a bot']]];
        yield 'non-string bot id' => [BotProtection::class, ['allow' => [42]]];
        yield 'non-list bot ids' => [BotProtection::class, ['allow' => ['bot' => 'CURL']]];
        yield 'invalid UTF8 allow bot id' => [BotProtection::class, ['allow' => ["\xff"]]];
        yield 'invalid UTF8 deny bot id' => [BotProtection::class, ['deny' => ["\xc3"]]];
        yield 'bare signed bot' => [BotProtection::class, ['allow' => ['SIGNED_BOT']]];
        foreach (['http://example.test/.well-known/http-message-signatures-directory', 'https://example.test/wrong', 'https://example.test/.well-known/http-message-signatures-directory?x=y', 'https://example.test/.well-known/http-message-signatures-directory#x', 'https://user:password@example.test/.well-known/http-message-signatures-directory', 'https://example.test/.well-known/http-message-signatures-directory/'] as $index => $url) {
            yield 'signed URL ' . $index => [BotProtection::class, ['allow' => ['SIGNED_BOT:' . $url]]];
        }
        yield 'bad characteristics' => [Shield::class, ['characteristics' => ['']]];
        yield 'invalid UTF8 characteristics' => [Shield::class, ['characteristics' => ["ip.\xff"]]];
        yield 'bad base HTTP' => [Options::class, ['baseUrl' => 'http://decide.arcjet.com']];
        yield 'bad base relative' => [Options::class, ['baseUrl' => '/decide']];
        yield 'bad base userinfo' => [Options::class, ['baseUrl' => 'https://key@decide.arcjet.com']];
        yield 'bad base query' => [Options::class, ['baseUrl' => 'https://decide.arcjet.com?x=y']];
        yield 'bad base empty query' => [Options::class, ['baseUrl' => 'https://decide.arcjet.com?']];
        yield 'bad base fragment' => [Options::class, ['baseUrl' => 'https://decide.arcjet.com#fragment']];
        yield 'bad base whitespace' => [Options::class, ['baseUrl' => "https://decide.arcjet.com/\n"]];
        yield 'bad base backslash' => [Options::class, ['baseUrl' => 'https://decide.arcjet.com/\\foo']];
        yield 'zero deadline' => [Options::class, ['deadlineMs' => 0]];
        yield 'negative deadline' => [Options::class, ['deadlineMs' => -1]];
        yield 'too long deadline' => [Options::class, ['deadlineMs' => 10000000000]];
        yield 'negative body cap' => [Options::class, ['maxBodyBytes' => -1]];
        yield 'too big body cap' => [Options::class, ['maxBodyBytes' => 1048577]];
        yield 'zero response cap' => [Options::class, ['maxResponseBytes' => 0]];
        yield 'too big response cap' => [Options::class, ['maxResponseBytes' => 1048577]];
        foreach (['10.0.0.0/33', '::/129', '10.0.0.0/-1', '10.0.0.0/', '10.0.0.0/1/2', '*', 'localhost', '127.000.000.001'] as $network) {
            yield 'bad proxy ' . $network => [Options::class, ['trustedProxies' => [$network]]];
        }
        yield 'bad redaction name' => [Options::class, ['redactHeaders' => ['not:a:header']]];
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationFailsAtConstruction(string $class, array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionClass($class))->newInstanceArgs($arguments);
    }

    public function testDefaultsAreDryRunAndValidSignedBotIsAccepted(): void
    {
        self::assertSame(Mode::DryRun, (new Shield())->mode);
        $rule = new BotProtection(allow: ['SIGNED_BOT:https://example.test/.well-known/http-message-signatures-directory']);
        self::assertSame(Mode::DryRun, $rule->mode);
        self::assertSame([], (new BotProtection(allow: []))->allow);
        self::assertSame(['FUTURE:My-Bot'], (new BotProtection(allow: ['FUTURE:My-Bot']))->allow);
    }

    public function testInvalidKeyFailsWithoutExposingValue(): void
    {
        $factory = new Psr17Factory();
        try {
            new Arcjet("secret\nkey", $this->allowingHttp(), $factory, $factory);
            self::fail('Expected invalid key to throw.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    public function testValidUnicodeAndFutureOpaqueConfigurationStringsRemainAccepted(): void
    {
        $identifiers = ['FUTURE:My-Bot', 'CATEGORY:FUTURE_CATEGORY', '未来:机器人'];
        self::assertSame($identifiers, (new BotProtection(allow: $identifiers))->allow);
        self::assertSame(['未来Bot'], (new BotProtection(deny: ['未来Bot']))->deny);
        self::assertSame(['ip.src', '属性'], (new Shield(characteristics: ['ip.src', '属性']))->characteristics);
    }

    public function testInvalidRuleObjectsAreRejectedAtConstruction(): void
    {
        $factory = new Psr17Factory();
        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionClass(Arcjet::class))->newInstanceArgs(['key', $this->allowingHttp(), $factory, $factory, [new \stdClass()]]);
    }
}
