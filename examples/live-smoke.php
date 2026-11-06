<?php

declare(strict_types=1);

/**
 * OPT-IN REAL SERVICE EXAMPLE with synthetic request metadata only.
 * Requires symfony/http-client and nyholm/psr7 in this checkout's vendor directory.
 * Set ARCJET_KEY through the environment, then run:
 *   php examples/live-smoke.php --live
 * This sends one LIVE protection request and can count toward account usage.
 */

use Arcjet\Arcjet;
use Arcjet\Mode;
use Arcjet\Rule\BotProtection;
use Arcjet\Rule\Shield;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    echo "CLI-only example; no request sent.\n";
    exit(2);
}
if (!in_array('--live', $argv, true)) {
    fwrite(STDERR, "No request sent. Explicit opt-in required: php examples/live-smoke.php --live\n");
    exit(2);
}
$key = getenv('ARCJET_KEY');
if ($key === false || $key === '') {
    fwrite(STDERR, "No request sent. Set ARCJET_KEY in the environment.\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists(Psr18Client::class) || !class_exists(Psr17Factory::class)) {
    fwrite(STDERR, "No request sent. Install symfony/http-client and nyholm/psr7 in this checkout.\n");
    exit(2);
}

$factory = new Psr17Factory();
$http = HttpClient::create([
    'timeout' => 0.5,
    'max_duration' => 1.0,
    'max_redirects' => 0,
    'verify_peer' => true,
    'verify_host' => true,
]);
$arcjet = new Arcjet(
    key: $key,
    httpClient: new Psr18Client($http, $factory, $factory),
    requestFactory: $factory,
    streamFactory: $factory,
    rules: [
        new Shield(mode: Mode::Live),
        new BotProtection(allow: [], mode: Mode::Live),
    ],
);
$request = new ServerRequest(
    method: 'GET',
    uri: 'https://example.com/arcjet-php-synthetic-smoke',
    headers: ['User-Agent' => 'ArcjetPhpSyntheticSmoke/1.0'],
    serverParams: ['REMOTE_ADDR' => '1.1.1.1'],
);

fwrite(STDERR, "Sending one real Arcjet request with synthetic metadata and LIVE rules.\n");
$decision = $arcjet->protect($request, correlationId: 'arcjet-php-synthetic-smoke');
// Do not print the key, raw service message, request headers, or response body.
printf("Conclusion: %s\n", $decision->conclusion->name);
printf("IP details: %s\n", $decision->hasIpDetails() ? 'present' : 'absent (not evidence of safety)');

if ($decision->isErrored()) {
    fwrite(STDERR, "Protection failed. Review configuration and private diagnostics; no live compatibility established.\n");
    exit(1);
}

echo "A non-error decision is not a complete bot/WAF/IP validation or production-readiness claim.\n";
