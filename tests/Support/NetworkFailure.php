<?php

declare(strict_types=1);

namespace Arcjet\Tests\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class NetworkFailure extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private RequestInterface $request)
    {
        parent::__construct('Sensitive network diagnostic must not be exposed.');
    }

    public function getRequest(): RequestInterface { return $this->request; }
}
