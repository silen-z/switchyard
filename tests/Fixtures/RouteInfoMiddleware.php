<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Switchyard\Found;
use SilenZ\Switchyard\MethodNotAllowed;

use function implode;

/**
 * Copies the routing result from the request attributes into response headers: the matched route's
 * name and tags from `Found::class`, or the allowed methods from `MethodNotAllowed::class`, so tests
 * can see what middleware is given.
 */
final class RouteInfoMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $found = Found::fromRequest($request);
        $methodNotAllowed = MethodNotAllowed::fromRequest($request);
        $response = $handler->handle($request);

        if ($found !== null) {
            $tags = implode(',', $found->tags);
            $response = $response->withHeader('X-Route-Name', $found->name ?? '')->withHeader('X-Route-Tags', $tags);
        }

        if ($methodNotAllowed !== null) {
            $response = $response->withHeader('X-Route-Allowed', implode(',', $methodNotAllowed->allowed));
        }

        return $response;
    }
}
