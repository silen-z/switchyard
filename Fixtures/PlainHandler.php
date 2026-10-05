<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A handler with no constructor dependencies, so a container can resolve it by class name without
 * having to know anything about it.
 */
final class PlainHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204);
    }
}
