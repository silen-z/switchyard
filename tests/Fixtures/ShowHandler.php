<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Switchyard\Attribute\Found;

/**
 * A route handler, resolved via the container like any PSR-15 handler would be. Echoes the "id"
 * route parameter, from the `Found::class` request attribute, back in a response header, so tests
 * can see it came through.
 */
final class ShowHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory->createResponse(200)->withHeader(
            'X-Id',
            Found::fromRequest($request)->params['id'] ?? '',
        );
    }
}
