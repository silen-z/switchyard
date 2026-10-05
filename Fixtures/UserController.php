<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\Found;

/**
 * A controller with ordinary methods, for handlers declared as `[UserController::class, 'show']`.
 */
final class UserController
{
    /**
     * Answers with the route's "id" parameter in an "X-User" header.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Found $found */
        $found = $request->getAttribute(Found::class);

        return new Response(200, ['X-User' => $found->params['id'] ?? '']);
    }

    /**
     * Not a valid handler method: it returns no response.
     */
    public function broken(ServerRequestInterface $request): string
    {
        return $request->getMethod();
    }
}
