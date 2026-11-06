<?php

declare(strict_types=1);

namespace Arcjet\Tests\Support;

use Arcjet\Arcjet;
use Arcjet\Internal\Proto;
use Arcjet\Options;
use Arcjet\Rule\BotProtection;
use Arcjet\Rule\Shield;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class SdkTestCase extends TestCase
{
    protected const string KEY = 'ajkey_test_private_not_a_real_key';

    /** @param list<Shield|BotProtection> $rules */
    protected function sdk(FakeHttpClient $http, array $rules = [], ?Options $options = null, ?LoggerInterface $logger = null): Arcjet
    {
        $factory = new Psr17Factory();
        return new Arcjet(self::KEY, $http, $factory, $factory, $rules, $logger, $options ?? new Options());
    }

    protected function request(string $peer = '198.51.100.42'): ServerRequest
    {
        return new ServerRequest('GET', 'https://example.test/a%2Fb?x=a%2Bb&x=c', ['User-Agent' => 'test browser'], null, '1.1', ['REMOTE_ADDR' => $peer]);
    }

    protected static function response(?Proto\Decision $decision = null): ResponseInterface
    {
        $decision ??= (new Proto\Decision())->setConclusion(Proto\Conclusion::CONCLUSION_ALLOW)->setId('decision_test');
        $bytes = (new Proto\DecideResponse())->setDecision($decision)->serializeToString();
        return new Response(200, ['Content-Type' => 'application/proto'], $bytes);
    }

    protected function allowingHttp(): FakeHttpClient
    {
        return new FakeHttpClient(static fn (): ResponseInterface => self::response());
    }

    protected static function decodeRequest(RequestInterface $request): Proto\DecideRequest
    {
        $message = new Proto\DecideRequest();
        $message->mergeFromString((string) $request->getBody());
        return $message;
    }
}
