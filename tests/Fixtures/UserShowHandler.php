<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Switchyard\Attribute\Found;

/**
 * An ordinary single-action handler, resolved by class name or container identifier like any other —
 * for a {@see \SilenZ\Switchyard\LazyRoutes} route, which can't name an instance's method the way
 * {@see UserController} does for {@see \SilenZ\Switchyard\Routes}.
 */
final class UserShowHandler implements RequestHandlerInterface
{
    /**
     * Answers with the route's "id" parameter in an "X-User" header.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['X-User' => Found::fromRequest($request)->params['id'] ?? '']);
    }
}
