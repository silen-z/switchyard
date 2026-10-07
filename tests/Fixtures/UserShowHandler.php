<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Http\Found;

/**
 * An ordinary single-action handler, resolved by class name or container identifier like any other —
 * for a {@see \SilenZ\Segmatch\Http\LazyRoutes} route, which can't name an instance's method the way
 * {@see UserController} does for {@see \SilenZ\Segmatch\Http\Routes}.
 */
final class UserShowHandler implements RequestHandlerInterface
{
    /**
     * Answers with the route's "id" parameter in an "X-User" header.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Found $found */
        $found = $request->getAttribute(Found::class);

        return new Response(200, ['X-User' => $found->params['id'] ?? '']);
    }
}
