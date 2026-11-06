<?php

declare(strict_types=1);

namespace Arcjet;

use Arcjet\Internal\IpAddress;
use Arcjet\Internal\Validation;

/** Immutable transport and disclosure settings. Transport timeouts/redirects remain caller-owned. */
final readonly class Options
{
    /**
     * @param list<string> $trustedProxies Exact IPs or CIDRs; never inferred from headers.
     * @param list<string> $redactHeaders Additional header names to omit (case-insensitive).
     */
    public function __construct(
        public string $baseUrl = 'https://decide.arcjet.com',
        public int $deadlineMs = 500,
        public array $trustedProxies = [],
        public bool $includeBody = false,
        public int $maxBodyBytes = 65536,
        public int $maxResponseBytes = 1048576,
        public array $redactHeaders = [],
    ) {
        Validation::httpsUrl($baseUrl);
        if ($deadlineMs < 1 || $deadlineMs > 9999999999) {
            throw new \InvalidArgumentException('deadlineMs must be between 1 and 9999999999.');
        }
        if ($maxBodyBytes < 0 || $maxBodyBytes > 1048576 || $maxResponseBytes < 1 || $maxResponseBytes > 1048576) {
            throw new \InvalidArgumentException('Body/response limits must not exceed 1 MiB; response limit must be positive.');
        }
        Validation::stringList($trustedProxies, 'trustedProxies');
        foreach ($trustedProxies as $proxy) {
            IpAddress::validateNetwork($proxy);
        }
        Validation::stringList($redactHeaders, 'redactHeaders');
        foreach ($redactHeaders as $name) {
            if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name)) {
                throw new \InvalidArgumentException('redactHeaders entries must be HTTP header names.');
            }
        }
    }
}
