<?php

declare(strict_types=1);

namespace Arcjet\Middleware;

use Arcjet\Arcjet;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Fail-closed by default. CHALLENGE is blocked, not rendered or verified. */
final readonly class ArcjetMiddleware implements MiddlewareInterface
{
    public const string DECISION_ATTRIBUTE = 'arcjet.decision';

    public function __construct(private Arcjet $arcjet, private ResponseFactoryInterface $responseFactory, private bool $failOpen = false) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $decision = $this->arcjet->protect($request);
        if ($decision->isErrored()) {
            if (!$this->failOpen || !$decision->isFailOpenEligible()) {
                return $this->responseFactory->createResponse(503)->withHeader('Cache-Control', 'no-store');
            }
        } elseif (!$decision->isAllowed()) {
            return $this->responseFactory->createResponse(403)->withHeader('Cache-Control', 'no-store');
        }
        return $handler->handle($request->withAttribute(self::DECISION_ATTRIBUTE, $decision));
    }
}
