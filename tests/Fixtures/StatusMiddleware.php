<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers with a fixed status itself, never reaching the handler, like an auth middleware turning a
 * request away.
 */
final class StatusMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly int $status,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response($this->status);
    }
}
