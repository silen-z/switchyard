<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Closure;
use SilenZ\Segmatch\NoMatch;

use function array_keys;
use function is_array;

/**
 * Matches HTTP routes against a request by running each candidate route's own guards:
 *
 *     $request = new Request($method);
 *     $result = $router->match($path, Guards::for($request));
 *
 *     if ($result instanceof RouteMatch) {
 *         // dispatch $result->route['handler'] with $result->params
 *     } elseif ($result->rejected === []) {
 *         // 404
 *     } else {
 *         // 405, with header Allow: implode(', ', Guards::allowedMethods($result, $request))
 *     }
 *
 * Routes without guards always apply.
 */
final class Guards
{
    /**
     * @param Request|string $request the request, or just its HTTP method
     *
     * @return Closure(mixed, array<string, string>): bool
     */
    public static function for(Request|string $request): Closure
    {
        $request = $request instanceof Request ? $request : new Request($request);

        return (
            /** @param array<string, string> $params */
            static fn(mixed $route, array $params): bool => self::accepts($route, $request, $params)
        );
    }

    /**
     * The HTTP methods the path supports for this request: those of the routes that were rejected
     * only because of their method. Routes rejected for any other reason (a parameter pattern, a
     * feature switch) don't count, so an empty list means 404 rather than 405.
     *
     * @return list<string>
     */
    public static function allowedMethods(NoMatch $result, Request|string $request): array
    {
        $request = $request instanceof Request ? $request : new Request($request);

        $allowed = [];
        foreach ($result->rejected as $match) {
            $methods = self::methods($match->route);
            if (
                $methods === null
                || !self::accepts($match->route, $request, $match->params, skip: MethodGuard::class)
            ) {
                continue;
            }

            foreach ($methods as $method) {
                $allowed[$method] = true;
            }
        }

        return array_keys($allowed);
    }

    /**
     * @param array<string, string> $params
     * @param ?class-string<Guard> $skip
     */
    private static function accepts(mixed $route, Request $request, array $params, ?string $skip = null): bool
    {
        // @mago-expect analysis:mixed-assignment
        foreach (self::guards($route) as $guard => $config) {
            // @mago-expect analysis:possibly-static-access-on-interface
            if ($guard !== $skip && !$guard::accepts($config, $request, $params)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The methods of a route's method guard, null when it has none (it accepts any method).
     *
     * @return ?list<string>
     */
    private static function methods(mixed $route): ?array
    {
        $guards = self::guards($route);
        if (!is_array($guards[MethodGuard::class] ?? null)) {
            return null;
        }

        /** @var list<string> */
        return $guards[MethodGuard::class];
    }

    /**
     * @return array<class-string<Guard>, mixed>
     */
    private static function guards(mixed $route): array
    {
        if (!is_array($route) || !is_array($route['guards'] ?? null)) {
            return [];
        }

        /** @var array<class-string<Guard>, mixed> */
        return $route['guards'];
    }
}
