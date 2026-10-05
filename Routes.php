<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\RouteDefinition;

use function array_unique;
use function array_values;
use function is_array;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strtoupper;

/**
 * HTTP route declarations: routes with methods and handlers, and groups of them.
 *
 *     $routes = new Routes();
 *     $routes->get('/', HomeController::class);
 *     $routes->group('/api')->middleware('api')->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
 *
 *     $router = new Router($routes->compiled());
 *     $resolver = new HandlerResolver($router, $responseFactory, registry: $routes->registry());
 *
 * Declaring runs immediately, like any other PHP code; there is nothing to defer. A `group()` is
 * itself a `Routes`, scoped by an optional path prefix, with its own middleware and tags inherited by
 * everything declared on it (including further nested groups). {@see compiled()} gives the callable
 * {@see \SilenZ\Segmatch\Router} takes: it only walks this tree into full paths and resolved metadata
 * when the router's cache has no entry, so declaring routes is cheap and unconditional, but turning
 * them into the compiled matching structure stays as lazy and cacheable as before.
 *
 * A handler, middleware entry or guard may be a real instance or closure, not just a class name or
 * container identifier: anything that isn't already cacheable plain data is transparently wrapped into
 * this tree's {@see Registry} instead, shared by the root and every nested group. Unlike the compiled
 * routes, the `Registry` is never cached — it's rebuilt fresh every time this tree is declared, so
 * {@see registry()} must be given to {@see HandlerResolver} alongside a `Router` built from the same
 * tree, every request.
 */
final class Routes
{
    private readonly Registry $registry;

    /** @var list<Route|self> */
    private array $items = [];

    /** @var list<mixed> */
    private array $middleware = [];

    /** @var list<string> */
    private array $tags = [];

    /**
     * @param string $prefix "" for no prefix (the root has none), otherwise starting with "/" and not
     *                       ending with "/"
     */
    public function __construct(
        private readonly string $prefix = '',
        ?Registry $registry = null,
    ) {
        if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_ends_with($prefix, '/'))) {
            throw new InvalidRouteException(sprintf(
                'Group prefix "%s" must start with "/" and must not end with "/"; use "" for no prefix.',
                $prefix,
            ));
        }

        $this->registry = $registry ?? new Registry();
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
     * A route for every HTTP method (it gets no 'methods' metadata, so it's never method-checked).
     */
    public function any(string $path, mixed $handler): Route
    {
        $route = new Route(null, $path, $handler, $this->registry);
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

        $route = new Route(array_values(array_unique($normalized)), $path, $handler, $this->registry);
        $this->items[] = $route;

        return $route;
    }

    /**
     * A group of routes with an optional path prefix, e.g. `$r->group('/admin')->middleware('auth')->get(...)`.
     * May be called more than once with the same prefix; each call adds a separate group, so sibling
     * groups never share middleware or tags. Shares this tree's {@see Registry}.
     */
    public function group(string $prefix = ''): self
    {
        $group = new self($prefix, $this->registry);
        $this->items[] = $group;

        return $group;
    }

    /**
     * Adds middleware for every route declared on this scope, including nested groups, after the
     * middleware of any enclosing group. A class name or container identifier is resolved as usual; a
     * real instance or closure is wrapped into the tree's {@see Registry} instead, transparently.
     *
     * @param mixed $middleware one middleware, or a list of them
     */
    public function middleware(mixed $middleware): self
    {
        $entries = is_array($middleware) ? array_values($middleware) : [$middleware];
        // Middleware is arbitrary user data, so its entries are mixed by definition.
        // @mago-expect analysis:mixed-assignment
        foreach ($entries as $entry) {
            $this->middleware[] = $this->registry->wrap($entry);
        }

        return $this;
    }

    /**
     * Tags every route declared on this scope, including nested groups, in addition to the tags of
     * enclosing groups and the routes' own.
     */
    public function tag(string ...$tags): self
    {
        $owner = $this->prefix === '' ? 'Routes without a prefix' : sprintf('Group "%s"', $this->prefix);
        $this->tags = [...$this->tags, ...Route::validTags($owner, $tags)];

        return $this;
    }

    /**
     * The callable {@see \SilenZ\Segmatch\Router} takes: resolves this scope's declared routes,
     * groups recursively, into core route definitions. Only called when the router's cache has no
     * entry for the key.
     *
     * @return callable(): list<RouteDefinition>
     */
    public function compiled(): callable
    {
        return function (): array {
            $definitions = [];
            $names = [];
            $this->register($definitions, '', [], [], $names);

            return $definitions;
        };
    }

    /**
     * The routes as declared: full paths and metadata, for tooling that needs the declarations
     * themselves, e.g. an index of routes by name, or generating documentation, not for matching
     * requests.
     *
     * @return list<RouteDefinition>
     */
    public function definitions(): array
    {
        return $this->compiled()();
    }

    /**
     * This tree's {@see Registry}, shared by the root and every nested group: give it to
     * {@see HandlerResolver} so it can resolve the ids standing in for real instances or closures in
     * the metadata {@see compiled()} produces. Rebuilt fresh every time this tree is declared, unlike
     * the compiled routes — so it must come from this same, current declaration, not a cached one.
     */
    public function registry(): Registry
    {
        return $this->registry;
    }

    /**
     * Resolves the declared routes into core route definitions, groups recursively.
     *
     * @internal
     *
     * @param list<RouteDefinition> $routes the resolved routes, appended to
     * @param string $prefix the enclosing groups' prefix
     * @param list<mixed> $middleware the enclosing groups' middleware
     * @param list<string> $tags the enclosing groups' tags
     * @param array<string, string> $names route name => path of the routes registered so far
     *
     * @throws InvalidRouteException
     */
    public function register(array &$routes, string $prefix, array $middleware, array $tags, array &$names): void
    {
        $prefix .= $this->prefix;
        $middleware = [...$middleware, ...$this->middleware];
        $tags = [...$tags, ...$this->tags];

        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item->register($routes, $prefix, $middleware, $tags, $names);
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
