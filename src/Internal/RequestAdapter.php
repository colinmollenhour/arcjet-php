<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\Internal\Proto\RequestDetails;
use Arcjet\Options;
use Psr\Http\Message\ServerRequestInterface;

/** @internal */
final readonly class RequestAdapter
{
    public function __construct(private Options $options, #[\SensitiveParameter] private string $key) {}

    public function convert(ServerRequestInterface $request, ?string $ip, ?string $correlationId): RequestDetails
    {
        $ip = IpAddress::resolve($request, $this->options->trustedProxies, $ip);
        if ($correlationId !== null && ($correlationId === '' || strlen($correlationId) > 256 || preg_match('/[^\x20-\x7e]/', $correlationId))) {
            throw new ProtectionException('invalid_correlation_id', 'Correlation ID must be 1–256 printable ASCII bytes.');
        }
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($uri->getPort() !== null) {
            $host .= ':' . $uri->getPort();
        }
        $path = $uri->getPath() !== '' ? $uri->getPath() : '/';
        $query = $uri->getQuery();
        $rawPath = $path;
        $wireQuery = $query === '' ? '' : '?' . $query;
        $target = $request->getRequestTarget();
        if (str_starts_with($target, '/')) {
            if (strlen($target) > 1048576) {
                throw new ProtectionException('request_too_large', 'The protection request exceeds 1 MiB.');
            }
            if (preg_match('/[^\x21-\x7e]|#/', $target)) {
                throw new ProtectionException('invalid_request_target', 'Origin-form request targets must be printable ASCII without fragments.');
            }
            $parts = explode('?', $target, 2);
            $rawPath = $parts[0];
            $wireQuery = isset($parts[1]) ? '?' . $parts[1] : '';
        }
        $headers = [];
        $redacted = array_map(strtolower(...), [...$this->options->redactHeaders, 'authorization', 'proxy-authorization']);
        $size = 0;
        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, $redacted, true)) {
                continue;
            }
            $size += strlen($name);
            foreach ($values as $value) {
                $size += strlen($value) + 2;
            }
            if ($size > 1048576) {
                throw new ProtectionException('request_too_large', 'The protection request exceeds 1 MiB.');
            }
            $headers[$lower] = implode($lower === 'cookie' ? '; ' : ', ', $values);
        }
        $details = (new RequestDetails())
            ->setIp($ip)
            ->setMethod($request->getMethod())
            ->setProtocol($uri->getScheme())
            ->setHost($host)
            ->setPath(rawurldecode($path))
            ->setRawPath($rawPath)
            ->setQuery($wireQuery)
            ->setHeaders($headers)
            ->setCookies($headers['cookie'] ?? '')
            ->setCorrelationId($correlationId ?? '');
        // The SDK has already applied the configured trust boundary. Cloud must
        // not reinterpret untrusted forwarding/vendor headers to replace this IP.
        $details->setExtra(['arcjet_disable_automatic_ip_detection' => 'true']);
        if ($this->options->includeBody) {
            $details->setBody(BoundedBody::request($request->getBody(), $this->options->maxBodyBytes));
        }
        // The SDK key must never be disclosed as inbound request metadata,
        // even if an application accidentally reflects it outside Authorization.
        if (str_contains($details->serializeToString(), $this->key)) {
            throw new ProtectionException('secret_in_request', 'The SDK key must not occur in request metadata.');
        }
        return $details;
    }
}
