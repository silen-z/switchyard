<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The first entry of the stacks {@see HandlerBuilder::build()} builds: sets the routing result
 * as a request attribute under its own class name, `Found::class` or `MethodNotAllowed::class`, for
 * the middleware and handler that follow.
 */
final readonly class RouteContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Found|MethodNotAllowed $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute($this->context::class, $this->context));
    }
}
