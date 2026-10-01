<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Closure;
use SilenZ\Segmatch\NoMatch;

use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
use function strtoupper;

/**
 * Turns the HTTP methods of {@see Routes} into a match-time guard:
 *
 *     $result = $router->match($path, MethodGuard::for($request->getMethod()));
 *
 *     if ($result instanceof NoMatch && $result->rejected !== []) {
 *         // 405 Method Not Allowed
 *         $allow = implode(', ', MethodGuard::allowed($result));
 *     }
 *
 * Routes for other methods are skipped as if they didn't exist, so a request can fall through to
 * another route (e.g. `GET /users/new` to `GET /users/{id}` when `/users/new` is POST only). When no
 * route accepts the method, the rejected routes tell which methods the path does support.
 */
final class MethodGuard
{
    /**
     * A guard accepting routes declared for the given method (case-insensitive) or for any method.
     *
     * @return Closure(mixed, array<string, string>): bool
     */
    public static function for(string $method): Closure
    {
        $method = strtoupper($method);

        return static fn(mixed $route): bool => self::accepts($route, $method);
    }

    /**
     * The methods of the routes a method guard rejected, for an `Allow` header.
     *
     * @return list<string>
     */
    public static function allowed(NoMatch $result): array
    {
        $allowed = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($result->rejected as $route) {
            foreach (self::methods($route) as $method) {
                $allowed[$method] = true;
            }
        }

        return array_keys($allowed);
    }

    private static function accepts(mixed $route, string $method): bool
    {
        $methods = self::methods($route);

        return in_array($method, $methods, strict: true) || in_array('*', $methods, strict: true);
    }

    /**
     * @return list<string>
     */
    private static function methods(mixed $route): array
    {
        if (!is_array($route) || !array_key_exists('methods', $route) || !is_array($route['methods'])) {
            return [];
        }

        /** @var list<string> */
        return $route['methods'];
    }
}
