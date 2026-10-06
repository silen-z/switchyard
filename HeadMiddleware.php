<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The first entry of every stack {@see HandlerBuilder::build()} builds for a HEAD request: runs
 * the rest as usual, then drops the response body. RFC 9110 forbids a body in a HEAD response but
 * wants the same headers as for GET, so status and headers, including `Content-Length`, are kept.
 * Applies whoever answers: a GET route standing in for HEAD, a HEAD or `any()` route, or the
 * not-found and method-not-allowed handlers.
 */
final readonly class HeadMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withBody($this->streamFactory->createStream(''));
    }
}
