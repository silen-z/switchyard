<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\Guard;

use function ctype_digit;
use function is_string;

/**
 * Accepts a route only when the parameter named in its configuration is all digits, as an
 * application's own parameter guard would.
 */
final class NumericGuard implements Guard
{
    public function accepts(mixed $config, ServerRequestInterface $request, array $params): bool
    {
        return is_string($config) && ctype_digit($params[$config] ?? '');
    }
}
