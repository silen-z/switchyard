<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\RouteFilter;
use SilenZ\Segmatch\RouteMatch;

/**
 * Accepts a route only for requests sent with the method in its constructor, so tests can see
 * which method a filter is shown.
 */
final class RequestMethodRouteFilter implements RouteFilter
{
    public function __construct(
        private readonly string $method,
    ) {}

    public function accepts(RouteMatch $match, ServerRequestInterface $request): bool
    {
        return $request->getMethod() === $this->method;
    }
}
