<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\Guard;

/**
 * Accepts a route only for requests sent with the method in its constructor, so tests can see
 * which method a guard is shown.
 */
final class RequestMethodGuard implements Guard
{
    public function __construct(
        private readonly string $method,
    ) {}

    public function accepts(ServerRequestInterface $request, array $params): bool
    {
        return $request->getMethod() === $this->method;
    }
}
