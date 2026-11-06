<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\FailureKind;
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
            throw new ProtectionException('invalid_correlation_id', 'Correlation ID must be 1–256 printable ASCII bytes.', FailureKind::Request);
        }
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($uri->getPort() !== null) {
            $host .= ':' . $uri->getPort();
        }
        $path = $uri->getPath() !== '' ? $uri->getPath() : '/';
        $query = $uri->getQuery();
        $rawPath = HttpMetadata::isOriginTarget($path) ? $path : '';
        $wireQuery = $query === '' ? '' : '?' . HttpMetadata::substituteInvalid($query);
        $server = $request->getServerParams();
        foreach ([$server['RAW_URI'] ?? null, $server['REQUEST_URI'] ?? null, $request->getRequestTarget()] as $target) {
            if (!is_string($target) || !HttpMetadata::isOriginTarget($target)) {
                continue;
            }
            if (strlen($target) > 1048576) {
                throw new ProtectionException('request_too_large', 'The protection request exceeds 1 MiB.', FailureKind::Request);
            }
            $parts = explode('?', $target, 2);
            $rawPath = $parts[0];
            $wireQuery = isset($parts[1]) ? '?' . $parts[1] : '';
            break;
        }
        $headers = [];
        $redacted = array_map(strtolower(...), [...$this->options->redactHeaders, 'authorization', 'proxy-authorization']);
        $size = 0;
        foreach ($request->getHeaders() as $name => $values) {
            // PHP arrays coerce digit-only header names to integer keys.
            $name = (string) $name;
            $lower = strtolower($name);
            if (in_array($lower, $redacted, true)) {
                continue;
            }
            $size += strlen($name);
            foreach ($values as $value) {
                $size += strlen($value) + 2;
            }
            if ($size > 1048576) {
                throw new ProtectionException('request_too_large', 'The protection request exceeds 1 MiB.', FailureKind::Request);
            }
            $headers[$lower] = implode($lower === 'cookie' ? '; ' : ', ', array_map(HttpMetadata::headerValue(...), $values));
        }
        $details = (new RequestDetails())
            ->setIp($ip)
            ->setMethod($request->getMethod())
            ->setProtocol($uri->getScheme())
            ->setHost(HttpMetadata::headerValue($host))
            ->setPath(HttpMetadata::substituteInvalid(rawurldecode($path)))
            ->setRawPath($rawPath)
            ->setQuery($wireQuery)
            ->setHeaders($headers)
            ->setCookies($headers['cookie'] ?? '')
            ->setCorrelationId($correlationId ?? '');
        // Source-grounded reporting marker matching Python's manual-IP mode.
        // Its service-side effect still requires authenticated live verification;
        // strict local IP selection does not rely on that unverified effect.
        $details->setExtra(['arcjet_disable_automatic_ip_detection' => 'true']);
        if ($this->options->includeBody) {
            $details->setBody(BoundedBody::request($request->getBody(), $this->options->maxBodyBytes));
        }
        // The SDK key must never be disclosed as inbound request metadata,
        // even if an application accidentally reflects it outside Authorization.
        if (str_contains($details->serializeToString(), $this->key)) {
            throw new ProtectionException('secret_in_request', 'The SDK key must not occur in request metadata.', FailureKind::Request);
        }
        return $details;
    }
}
