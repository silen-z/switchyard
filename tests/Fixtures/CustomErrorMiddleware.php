<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * An application's own error middleware: answers with a fixed status when the rest of the stack
 * throws, so tests can tell it apart from the default {@see \SilenZ\Segmatch\Http\ErrorMiddleware}.
 */
final class CustomErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly int $status,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable) {
            return new Response($this->status);
        }
    }
}
