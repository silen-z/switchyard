<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The first entry of every stack {@see HandlerResolver::resolve()} builds for a HEAD request: runs
 * the rest as usual, then drops the response body. RFC 9110 forbids a body in a HEAD response but
 * wants the same headers as for GET, so status and headers, including `Content-Length`, are kept.
 * Applies whoever answers: a GET route standing in for HEAD, a HEAD or `any()` route, or the
 * not-found and method-not-allowed handlers.
 */
final readonly class HeadMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A fresh response's body is an empty stream; PSR-17 has no cheaper way to get one here.
        return $handler->handle($request)->withBody($this->responseFactory->createResponse()->getBody());
    }
}
