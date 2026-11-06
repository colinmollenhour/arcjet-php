<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\Arcjet;
use Arcjet\Options;
use Psr\Http\Client\ClientInterface;
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
            throw new ProtectionException('request_too_large', 'The protection request exceeds 1 MiB.');
        }
        $request = $this->requestFactory->createRequest('POST', rtrim($this->options->baseUrl, '/') . '/proto.decide.v1alpha1.DecideService/Decide')
            ->withHeader('Content-Type', 'application/proto')
            ->withHeader('Accept', 'application/proto')
            ->withHeader('Connect-Protocol-Version', '1')
            ->withHeader('Connect-Timeout-Ms', (string) $this->options->deadlineMs)
            ->withHeader('Authorization', 'Bearer ' . $this->key)
            ->withHeader('User-Agent', 'arcjet-php/' . Arcjet::VERSION)
            ->withBody($this->streamFactory->createStream($protobuf));
        $response = $this->client->sendRequest($request);
        $body = $response->getBody();
        try {
            if ($response->getStatusCode() !== 200) {
                throw new ProtectionException('http_status', 'Arcjet returned a non-success HTTP status.');
            }
            if (strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0])) !== 'application/proto') {
                throw new ProtectionException('content_type', 'Arcjet returned an unexpected response content type.');
            }
            $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
            if ($encoding !== '' && $encoding !== 'identity') {
                throw new ProtectionException('content_encoding', 'Arcjet returned an unsupported response content encoding.');
            }
            if ($body->isSeekable()) {
                $body->rewind();
            }
            return BoundedBody::read($body, $this->options->maxResponseBytes, 'response_too_large');
        } finally {
            $body->close();
        }
    }
}
