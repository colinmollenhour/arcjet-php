# Arcjet PHP

Arcjet PHP adds remote Bot Protection, Shield web application firewall (WAF), and IP Analysis to PHP 8.4+ applications through PHP Standards Recommendations (PSR) interfaces.

**Community, unofficial, and experimental.** Authenticated synthetic interoperability was observed on PHP 8.5 and a smaller PHP 8.4 subset on 2026-11-06; see the [live validation scope and limits](docs/live-validation.md). This is not production certification or an official or Arcjet-endorsed SDK. See [compatibility and verification status](docs/compatibility.md).

## Install from a local checkout

Requires PHP 8.4+, Composer 2, and the `ctype`, `filter`, and `json` PHP extensions. The Composer package name is `arcjet/arcjet-php`; no published Packagist release is assumed. Source is available in the [public GitHub repository](https://github.com/colinmollenhour/arcjet-php). In your application's Composer project, register your local checkout and install a real HTTP client and PSR factories:

```sh
composer config repositories.arcjet-php path /ABSOLUTE/PATH/TO/arcjet-php
composer require 'arcjet/arcjet-php:@dev' symfony/http-client nyholm/psr7 nyholm/psr7-server
```

`nyholm/psr7-server` creates the incoming request in the quickstart. Applications that already provide a PSR-7 server request do not need it. The SDK requires an injected PSR-18 HTTP client and PSR-17 request and stream factories; it does not bundle or discover an HTTP implementation.

Run `composer check-platform-reqs --no-dev` on the deployment runtime; do not bypass missing extension requirements. For autoload-time extension checks, set `composer config platform-check true` in the application and regenerate its autoloader with `composer dump-autoload`.

## Protect an HTTP request

Set `ARCJET_KEY` in your application's environment with an Arcjet test site key. Save this front controller as `protect.php` in your application's directory. It sends request metadata to the real Arcjet service; review the [disclosure reference](docs/api.md#request-disclosure) before using real traffic.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Arcjet\Arcjet;
use Arcjet\Conclusion;
use Arcjet\Mode;
use Arcjet\Rule\BotProtection;
use Arcjet\Rule\Shield;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$key = getenv('ARCJET_KEY');
if ($key === false || $key === '') {
    throw new RuntimeException('Set ARCJET_KEY in the application environment.');
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
        new BotProtection(allow: ['CATEGORY:SEARCH_ENGINE'], mode: Mode::Live),
    ],
);
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
$decision = $arcjet->protect($request);
$status = match ($decision->conclusion) {
    Conclusion::Allow => 200,
    Conclusion::Deny, Conclusion::Challenge => 403,
    default => 503,
};
http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
echo match ($status) {
    200 => "Request allowed.\n",
    403 => "Request blocked.\n",
    default => "Protection unavailable.\n",
};
```

For a local check, run `php -S 127.0.0.1:8080 protect.php`, then visit `http://127.0.0.1:8080/`. This makes a real service call; it is not a production server configuration.

The HTTP client's `timeout` bounds inactivity, including connection establishment; `max_duration` bounds the total request. TLS verification and disabled redirects protect the outbound credential. The SDK's separate `Connect-Timeout-Ms` header is **not** a local transport timeout.

The default service deadline is 500 ms. Short budgets limit added latency but can turn transient slowness into HTTP 503 under this example's fail-closed policy; longer budgets occupy PHP workers for longer. Tune `Options::deadlineMs` and the transport timeouts together using observed service latency and your application's availability budget.

Both rules above explicitly use `Mode::Live`. The deliberate PHP default is `Mode::DryRun`: inspect the aggregate decision, not individual rule denials. `ERROR` is neither allowed nor denied. PSR-15 middleware defaults to fail-closed; optional fail-open applies only to classified transport/service errors, never request/internal failures. See the [API reference](docs/api.md#psr-15-middleware).

## Run the examples

From this checkout, install development dependencies and run the synthetic example:

```sh
composer install
php examples/offline.php
```

It uses Nyholm PSR-7 objects and a fake PSR-18 client. **It makes no network calls and performs no protection evaluation.**

The opt-in [live smoke example](examples/live-smoke.php) requires a real transport, `ARCJET_KEY`, and the explicit `--live` argument. For this script, add the optional transport to your local checkout (this command changes its Composer files):

```sh
composer require --dev symfony/http-client nyholm/psr7
php examples/live-smoke.php --live
```

It sends one synthetic request using LIVE rules. It does not reproduce the [authenticated validation matrix](docs/live-validation.md) or provide a complete security or release-validation test.

## Documentation

- [API reference](docs/api.md): configuration, rule semantics, decisions, request disclosure, and IP fields.
- [Compatibility and limits](docs/compatibility.md): deliberate Python deviations and remaining validation gates.
- [Authenticated live validation](docs/live-validation.md): dated run scope, observed outcomes, and untested claims.
- [Architecture](docs/architecture.md): remote evaluation, trust boundaries, and operational costs.
- [Contributing](CONTRIBUTING.md) and [security policy](SECURITY.md).
- [Apache-2.0 license](LICENSE) and [third-party notices](NOTICE).
