# How to contribute

## Run the checks

Use PHP 8.4 or later and Composer 2. From the repository root, run:

```bash
composer install
composer check
```

The checks validate package metadata, lint PHP, run offline PHPUnit tests, and analyze authored code with PHPStan. The CI matrix runs on PHP 8.4 and 8.5.

To run the tests at the supported minimum version with Docker:

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/app" -w /app php:8.4-cli \
  php vendor/bin/phpunit --do-not-cache-result
```

## Preserve the protocol contract

Treat generated protobuf files as build outputs, not hand-edited source. Schema and fixture changes need provenance from the pinned upstream descriptor and reproducible generation. The offline fixtures must not contain API keys, real customer requests, or confidential data.

Add regression tests for request normalization, rule configuration, decision decoding, or middleware behavior when changing those areas. Test that the original PSR-7 request and body stream remain usable by the application.

Do not replace service-side protection with heuristic bot matching or a local WAF approximation. Keep unsupported features and behavioral deviations explicit in the documentation.

## Separate offline and live verification

The default test suite makes no external service requests. It must run without an Arcjet key.

Authenticated service tests need a dedicated test-site key and synthetic traffic. Never commit keys or captured sensitive requests. Passing offline fixtures does not establish service acceptance or production protection; record live evidence separately.

## Submit a change

Use a feature branch and a pull request. Include the behavior changed, security and compatibility implications, exact checks run, and any remaining verification gaps. See [SECURITY.md](SECURITY.md) for private vulnerability reporting.
