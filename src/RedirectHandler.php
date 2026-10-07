<?php

declare(strict_types=1);

namespace SilenZ\Switchyard;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redirects to `$location`, 308 by default — permanent, preserving the method and body, unlike a 301
 * or 302. {@see HandlerBuilder::build()} uses one for a path whose trailing "/" counterpart answers
 * instead; a route may just as well use one directly as its own handler, e.g. for a moved path:
 *
 *     $routes->get('/old', new RedirectHandler($responseFactory, '/new'));
 */
final readonly class RedirectHandler implements RequestHandlerInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private string $location,
        private int $status = 308,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory->createResponse($this->status)->withHeader('Location', $this->location);
    }
}
