<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use function in_array;
use function is_array;

/**
 * Accepts a route only for the HTTP methods it was declared with. Added by the verb helpers of
 * {@see RouteCollector} (`get()`, `post()`, `map()`, ...), with the methods as configuration;
 * `any()` routes don't get one.
 */
final class MethodGuard implements Guard
{
    public static function accepts(mixed $config, Request $request, array $params): bool
    {
        return is_array($config) && in_array($request->method, $config, strict: true);
    }
}
