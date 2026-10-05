<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\Guard;

use function ctype_digit;

/**
 * Accepts a route only when the parameter named in its constructor is all digits, as an
 * application's own parameter guard would.
 */
final class NumericGuard implements Guard
{
    public function __construct(
        private readonly string $param,
    ) {}

    public function accepts(ServerRequestInterface $request, array $params): bool
    {
        return ctype_digit($params[$this->param] ?? '');
    }
}
