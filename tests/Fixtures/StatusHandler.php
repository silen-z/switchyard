<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An application's own not-found handler: answers with a fixed status, so tests can tell it apart
 * from the default.
 */
final class StatusHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly int $status,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response($this->status);
    }
}
