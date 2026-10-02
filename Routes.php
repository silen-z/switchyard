<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\RouteDefinition;

use function array_unique;
use function array_values;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strtoupper;

/**
 * HTTP route declarations: routes with methods and handlers, and groups of them.
 *
 *     $router = new Router(Routes::define(static function (Routes $r): void {
 *         $r->get('/', HomeController::class);
 *         $r->group('/api')->middleware('api')->define(static function (Routes $r): void {
 *             $r->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
 *         });
 *     }));
 *
 * Declarations are only recorded while defining; they are resolved into full paths and metadata for
 * core {@see RouteDefinition}s once everything has been declared. Routes keep their declaration order,
 * which decides between routes sharing a path.
 */
final class Routes
{
    /** @var list<Route|Group> */
    private array $items = [];

    /**
     * Turns a route definition into the route callable {@see \SilenZ\Segmatch\Router} takes. The
     * definition may be a closure or an invokable class; like any route callable, it only runs when
     * the router's cache has no entry.
     *
     * @param callable(Routes): void $definition
     * @return callable(): array<RouteDefinition>
     */
    public static function define(callable $definition): callable
    {
        return static function () use ($definition): array {
            $routes = new self();
            $definition($routes);

            $definitions = [];
            $names = [];
            $routes->register($definitions, '', [], [], $names);

            return $definitions;
        };
    }

    public function get(string $path, mixed $handler): Route
    {
        return $this->map(['GET'], $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->map(['POST'], $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->map(['PUT'], $path, $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->map(['PATCH'], $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->map(['DELETE'], $path, $handler);
    }

    public function options(string $path, mixed $handler): Route
    {
        return $this->map(['OPTIONS'], $path, $handler);
    }

    /**
     * A route for every HTTP method (it gets no {@see MethodGuard}).
     */
    public function any(string $path, mixed $handler): Route
    {
        $route = new Route(null, $path, $handler);
        $this->items[] = $route;

        return $route;
    }

    /**
     * A route for the given HTTP methods (case-insensitive).
     *
     * @param list<string> $methods
     */
    public function map(array $methods, string $path, mixed $handler): Route
    {
        $normalized = [];
        foreach ($methods as $method) {
            $upper = strtoupper($method);
            if (preg_match('/^[A-Z]+$/', $upper) !== 1) {
                throw new InvalidRouteException(sprintf('Route "%s" has an invalid HTTP method "%s".', $path, $method));
            }

            $normalized[] = $upper;
        }

        if ($normalized === []) {
            throw new InvalidRouteException(sprintf('Route "%s" needs at least one HTTP method.', $path));
        }

        $route = new Route(array_values(array_unique($normalized)), $path, $handler);
        $this->items[] = $route;

        return $route;
    }

    /**
     * A group of routes with an optional path prefix, e.g. `$r->group('/admin')->middleware('auth')->define(...)`.
     */
    public function group(string $prefix = ''): Group
    {
        $group = new Group($prefix);
        $this->items[] = $group;

        return $group;
    }

    /**
     * Resolves the collected routes into core route definitions, groups recursively.
     *
     * @internal
     *
     * @param list<RouteDefinition> $routes the resolved routes, appended to
     * @param list<mixed> $middleware middleware of the enclosing groups
     * @param list<string> $tags tags of the enclosing groups
     * @param array<string, string> $names route name => path of the routes registered so far
     *
     * @throws InvalidRouteException
     */
    public function register(array &$routes, string $prefix, array $middleware, array $tags, array &$names): void
    {
        foreach ($this->items as $item) {
            if ($item instanceof Group) {
                $item->collect()->register(
                    $routes,
                    $prefix . $item->prefix(),
                    [...$middleware, ...$item->groupMiddleware()],
                    [...$tags, ...$item->groupTags()],
                    $names,
                );
                continue;
            }

            $routes[] = self::resolve($item, $prefix, $middleware, $tags, $names);
        }
    }

    /**
     * @param list<mixed> $middleware
     * @param list<string> $tags
     * @param array<string, string> $names
     *
     *
     * @throws InvalidRouteException
     */
    private static function resolve(
        Route $route,
        string $prefix,
        array $middleware,
        array $tags,
        array &$names,
    ): RouteDefinition {
        $path = $route->path();
        // Inside a group with a prefix, "" declares a route on the prefix itself.
        if (!($path === '' && $prefix !== '') && !str_starts_with($path, '/')) {
            throw new InvalidRouteException(sprintf('Route path "%s" must start with "/".', $path));
        }

        $fullPath = $prefix . $path;

        $name = $route->routeName();
        if ($name !== null) {
            if (($names[$name] ?? null) !== null) {
                throw new InvalidRouteException(sprintf(
                    'Route name "%s" is used by both "%s" and "%s".',
                    $name,
                    $names[$name],
                    $fullPath,
                ));
            }

            $names[$name] = $fullPath;
        }

        return new RouteDefinition($fullPath, $route->metadata($fullPath, $middleware, $tags));
    }
}
