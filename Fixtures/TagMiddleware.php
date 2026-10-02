<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function implode;

/**
 * Appends its own name to a comma-separated "X-Trail" header, so tests can see middleware ran
 * (and in which order) around the handler.
 */
final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $trail = [...$response->getHeader('X-Trail'), $this->name];

        return $response->withHeader('X-Trail', implode(',', $trail));
    }
}
