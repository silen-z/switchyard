<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Http\Found;
use SilenZ\Segmatch\Http\MethodNotAllowed;

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
        // @mago-expect analysis:mixed-assignment
        $found = $request->getAttribute(Found::class);
        // @mago-expect analysis:mixed-assignment
        $methodNotAllowed = $request->getAttribute(MethodNotAllowed::class);
        $response = $handler->handle($request);

        if ($found instanceof Found) {
            $tags = implode(',', $found->tags);
            $response = $response->withHeader('X-Route-Name', $found->name ?? '')->withHeader('X-Route-Tags', $tags);
        }

        if ($methodNotAllowed instanceof MethodNotAllowed) {
            $response = $response->withHeader('X-Route-Allowed', implode(',', $methodNotAllowed->allowed));
        }

        return $response;
    }
}
