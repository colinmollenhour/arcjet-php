<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\Arcjet;
use Arcjet\FailureKind;
use Arcjet\Options;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** @internal No redirects, retries, cache or Report RPC are implemented by this layer. */
final readonly class DecideTransport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Options $options,
        #[\SensitiveParameter] private string $key,
    ) {}

    public function send(string $protobuf): string
    {
        if (strlen($protobuf) > 1048576) {
            throw new ProtectionException('request_too_large', 'The protection request exceeds 1 MiB.', FailureKind::Request);
        }
        $request = $this->requestFactory->createRequest('POST', rtrim($this->options->baseUrl, '/') . '/proto.decide.v1alpha1.DecideService/Decide')
            ->withHeader('Content-Type', 'application/proto')
            ->withHeader('Accept', 'application/proto')
            ->withHeader('Connect-Protocol-Version', '1')
            ->withHeader('Connect-Timeout-Ms', (string) $this->options->deadlineMs)
            ->withHeader('Authorization', 'Bearer ' . $this->key)
            ->withHeader('User-Agent', 'arcjet-php/' . Arcjet::VERSION)
            ->withBody($this->streamFactory->createStream($protobuf));
        try {
            $response = $this->client->sendRequest($request);
        } catch (RequestExceptionInterface) {
            // This is the SDK's prepared outbound request, not an outage signal.
            throw new ProtectionException('invalid_prepared_request', 'The HTTP client rejected the prepared Arcjet request.', FailureKind::Internal);
        } catch (ClientExceptionInterface) {
            // Only explicit PSR-18 operational failures at the send boundary are
            // Transport. Arbitrary exceptions and preparation bugs stay Internal.
            throw new ProtectionException('transport_error', 'The HTTP client could not complete the Arcjet request.', FailureKind::Transport);
        }
        $body = $response->getBody();
        try {
            if ($response->getStatusCode() !== 200) {
                throw new ProtectionException('http_status', 'Arcjet returned a non-success HTTP status.', FailureKind::Service);
            }
            if (strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0])) !== 'application/proto') {
                throw new ProtectionException('content_type', 'Arcjet returned an unexpected response content type.', FailureKind::Service);
            }
            $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
            if ($encoding !== '' && $encoding !== 'identity') {
                throw new ProtectionException('content_encoding', 'Arcjet returned an unsupported response content encoding.', FailureKind::Service);
            }
            if ($body->isSeekable()) {
                $body->rewind();
            }
            return BoundedBody::read($body, $this->options->maxResponseBytes, 'response_too_large', FailureKind::Service);
        } finally {
            $body->close();
        }
    }
}
