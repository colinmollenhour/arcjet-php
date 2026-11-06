<?php

declare(strict_types=1);

namespace Arcjet\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param \Closure(RequestInterface): ResponseInterface $responder */
    public function __construct(private \Closure $responder) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        return ($this->responder)($request);
    }
}
