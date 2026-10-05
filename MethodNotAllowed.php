<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use function in_array;
use function is_array;
use function strtoupper;

/**
 * Routes exist for the request's path, but not for its method. {@see HandlerResolver::resolve()}
 * hands this to the method-not-allowed and OPTIONS handlers under the `MethodNotAllowed::class`
 * request attribute.
 *
 * {@see accepts()} and {@see of()} read a route's own HTTP methods, stored in its metadata directly
 * rather than as a {@see Guard}; see {@see Route}. Routes declared with `any()` have none and accept
 * every method. Generic and container-free, unlike guard resolution: {@see HandlerResolver} composes
 * these with its own guard handling to build the closure {@see \SilenZ\Segmatch\Router::match()} takes.
 */
final readonly class MethodNotAllowed
{
    /**
     * @param non-empty-list<string> $allowed the path's methods, HEAD included whenever GET is
     */
    public function __construct(
        public array $allowed,
    ) {}

    /**
     * Whether the route accepts the given HTTP method (case-insensitive). True for a route without
     * methods (`any()`).
     */
    public static function accepts(mixed $route, string $method): bool
    {
        $methods = self::of($route);

        return $methods === null || in_array(strtoupper($method), $methods, strict: true);
    }

    /**
     * The route's own HTTP methods, null when it has none (it accepts any method).
     *
     * @return ?list<string>
     */
    public static function of(mixed $route): ?array
    {
        if (!is_array($route) || !is_array($route['methods'] ?? null)) {
            return null;
        }

        /** @var list<string> */
        return $route['methods'];
    }
}
