<?php

declare(strict_types=1);

namespace Arcjet\Tests\Support;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class PreparedRequestFailure extends \RuntimeException implements RequestExceptionInterface
{
    public function __construct(private RequestInterface $request)
    {
        parent::__construct('Sensitive prepared request diagnostic must not be exposed.');
    }

    public function getRequest(): RequestInterface { return $this->request; }
}
