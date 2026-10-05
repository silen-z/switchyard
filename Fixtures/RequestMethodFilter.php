<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\Filter;
use SilenZ\Segmatch\RouteMatch;

/**
 * Accepts a route only for requests sent with the method in its constructor, so tests can see
 * which method a filter is shown.
 */
final class RequestMethodFilter implements Filter
{
    public function __construct(
        private readonly string $method,
    ) {}

    public function accepts(ServerRequestInterface $request, RouteMatch $match): bool
    {
        return $request->getMethod() === $this->method;
    }
}
