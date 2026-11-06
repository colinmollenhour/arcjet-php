<?php

declare(strict_types=1);

/**
 * SYNTHETIC OFFLINE EXAMPLE: no network calls or protection evaluation.
 * Run from the checkout after `composer install`: php examples/offline.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Arcjet\Arcjet;
use Arcjet\Conclusion;
use Arcjet\Decision;
use Arcjet\Middleware\ArcjetMiddleware;
use Arcjet\Options;
use Arcjet\Rule\BotProtection;
use Arcjet\Rule\Shield;
use Arcjet\RuleState;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$factory = new Psr17Factory();
$fakeHttp = new class ($factory) implements ClientInterface {
    public function __construct(private readonly Psr17Factory $factory) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Fixed synthetic DecideResponse: aggregate ALLOW, one DRY_RUN/DENY result.
        // This is a fixture, not a bot detector, service response, or HTTP implementation.
        $protobuf = "\x0a\x1b\x0a\x11synthetic-offline\x10\x01\x22\x04\x10\x03\x18\x02";

        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'application/proto')
            ->withBody($this->factory->createStream($protobuf));
    }
};

$arcjet = new Arcjet(
    // Public fixture placeholder only: never sent to a network or usable as a key.
    key: 'synthetic-offline-not-a-service-key',
    httpClient: $fakeHttp,
    requestFactory: $factory,
    streamFactory: $factory,
    rules: [new Shield(), new BotProtection(allow: [])],
);
$body = $factory->createStream('synthetic request body');
$body->seek(5);
$request = new ServerRequest(
    method: 'POST',
    uri: 'https://example.com/offline?synthetic=1',
    headers: ['User-Agent' => 'SyntheticOfflineExample/1.0'],
    body: $body,
    serverParams: ['REMOTE_ADDR' => '203.0.113.10'],
);

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$decision = $arcjet->protect($request);
$check($decision->isAllowed(), 'Expected the synthetic aggregate ALLOW.');
$check(count($decision->results) === 1, 'Expected one synthetic rule result.');
$check($decision->results[0]->state === RuleState::DryRun, 'Expected DRY_RUN state.');
$check($decision->results[0]->conclusion === Conclusion::Deny, 'Expected a synthetic rule DENY.');
$check($body->tell() === 5, 'Default protection consumed the request body.');

$handler = new class ($factory) implements RequestHandlerInterface {
    public function __construct(private readonly Psr17Factory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $decision = $request->getAttribute(ArcjetMiddleware::DECISION_ATTRIBUTE);
        if (!$decision instanceof Decision || !$decision->isAllowed()) {
            throw new RuntimeException('Middleware did not attach the synthetic decision.');
        }

        return $this->factory->createResponse(200);
    }
};
$response = (new ArcjetMiddleware($arcjet, $factory))->process($request, $handler);
$check($response->getStatusCode() === 200, 'Expected middleware to delegate ALLOW.');

$withBody = new Arcjet(
    key: 'synthetic-offline-not-a-service-key',
    httpClient: $fakeHttp,
    requestFactory: $factory,
    streamFactory: $factory,
    options: new Options(includeBody: true),
);
$check($withBody->protect($request)->isAllowed(), 'Synthetic opt-in body call failed.');
$check($body->tell() === 5, 'Opt-in protection did not restore the body position.');

echo "Synthetic offline only: no network or protection evaluation.\n";
echo "Decision: Allow; dry-run rule: Deny.\n";
echo "Middleware: 200; body position preserved (default and opt-in).\n";
