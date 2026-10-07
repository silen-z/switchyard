<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * A default answer for whatever the rest of the stack throws: a plain-text 500, swallowing the
 * {@see Throwable} rather than letting it escape {@see HandlerBuilder::build()}'s caller. Only useful
 * placed outermost — e.g. first into {@see Routes::middleware()} on the root — since that's the one
 * spot that wraps every other middleware and handler, matched or not.
 *
 * Replace it with middleware of the same shape for anything beyond a generic response: logging the
 * exception, a formatted body, exposing it in development, etc.
 */
final readonly class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable) {
            return $this->responseFactory
                ->createResponse(500)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody($this->streamFactory->createStream('Server error'));
        }
    }
}
