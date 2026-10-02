<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\RouteMatch;

use function array_keys;
use function array_search;
use function array_splice;
use function in_array;
use function is_array;
use function strtoupper;

/**
 * A route's own HTTP methods, stored in its metadata directly rather than as a {@see Guard}; see
 * {@see Route}. Routes declared with `any()` have none and accept every method.
 *
 * Generic and container-free, unlike guard resolution: {@see Dispatcher} composes this with its own
 * guard handling to build the closure {@see \SilenZ\Segmatch\Router::match()} takes, and to compute
 * what a 405 or OPTIONS response needs via {@see allowed()}.
 */
final class Methods
{
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

    /**
     * The HTTP methods of rejected routes whose own guards still accept the request, e.g. for a 405's
     * `Allow` header. `$guardsAccept` decides whether a candidate's own guards (not its methods) let
     * it through; {@see Dispatcher} is the one that knows how to resolve and run them.
     *
     * @param list<RouteMatch> $rejected e.g. {@see \SilenZ\Segmatch\NoMatch::$rejected} or
     *     {@see \SilenZ\Segmatch\Router::matchAll()}
     * @param callable(mixed $route, array<string, string> $params): bool $guardsAccept
     *
     * @return list<string>
     */
    public static function allowed(array $rejected, callable $guardsAccept): array
    {
        $allowed = [];
        foreach ($rejected as $match) {
            $methods = self::of($match->route);
            if ($methods === null || !$guardsAccept($match->route, $match->params)) {
                continue;
            }

            foreach ($methods as $method) {
                $allowed[$method] = true;
            }
        }

        return array_keys($allowed);
    }

    /**
     * Adds HEAD right after GET, since HEAD requests match GET routes.
     *
     * @param list<string> $methods
     *
     * @return list<string>
     */
    public static function withHead(array $methods): array
    {
        $get = array_search('GET', $methods, strict: true);
        if ($get !== false && !in_array('HEAD', $methods, strict: true)) {
            array_splice($methods, $get + 1, length: 0, replacement: ['HEAD']);
        }

        return $methods;
    }
}
