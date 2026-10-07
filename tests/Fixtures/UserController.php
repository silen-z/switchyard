<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Switchyard\Attribute\Found;

/**
 * A controller with ordinary methods, for a handler declared as `[$instance, 'method']` — a plain
 * instance pair, which Relay calls directly as a callable; segmatch has no special handling for it at
 * all. Only usable with {@see \SilenZ\Switchyard\Routes}: a {@see \SilenZ\Switchyard\LazyRoutes}
 * handler must be a plain string, so it can't name an instance's method this way.
 */
final class UserController
{
    /**
     * Answers with the route's "id" parameter in an "X-User" header.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['X-User' => Found::fromRequest($request)->params['id'] ?? '']);
    }

    /**
     * Not a valid handler method: it returns no response.
     */
    public function broken(ServerRequestInterface $request): string
    {
        return $request->getMethod();
    }
}
