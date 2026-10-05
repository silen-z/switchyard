<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\Filter;
use SilenZ\Segmatch\RouteMatch;

use function ctype_digit;

/**
 * Accepts a route only when the parameter named in its constructor is all digits, as an
 * application's own parameter filter would.
 */
final class NumericFilter implements Filter
{
    public function __construct(
        private readonly string $param,
    ) {}

    public function accepts(ServerRequestInterface $request, RouteMatch $match): bool
    {
        return ctype_digit($match->params[$this->param] ?? '');
    }
}
