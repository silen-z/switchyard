<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\CallableRouteTable;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\RouteTable;

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
 *     $routes = new Routes(new Registry());
 *     $routes->get('/', HomeController::class);
 *     $routes->group('/api')->middleware('api')->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
 *
 *     $router = new Router($routes->table('routes-' . APP_VERSION), cache: new FileCache($dir));
 *
 * Or, to have one tree answer its own requests, {@see RoutesHandlerBuilder} owns it:
 *
 *     $builder = new RoutesHandlerBuilder($container);
 *     $builder->routes()->get('/', HomeController::class);
 *
 *     $response = $builder->handler($request)->handle($request);
 *
 * Declaring runs immediately, like any other PHP code; there is nothing to defer. A `group()` is
 * itself a `Routes`, scoped by an optional path prefix, with its own middleware and tags inherited by
 * everything declared on it (including further nested groups). {@see table()} gives the
 * {@see \SilenZ\Segmatch\RouteTable} `Router` takes: it only walks this tree into full paths and
 * resolved metadata when the router's cache has no entry, so declaring routes is cheap and
 * unconditional, but turning them into the compiled matching structure stays as lazy and cacheable as
 * before.
 *
 * A handler, middleware entry or filter may be a real instance or closure, not just a class name or
 * container identifier: anything that isn't already cacheable plain data is transparently wrapped into
 * this tree's {@see Registry} instead, shared by the root and every nested group. Unlike the compiled
 * routes, the `Registry` is never cached — it's rebuilt fresh every time this tree is declared, which
 * is why {@see RoutesHandlerBuilder} owns the tree it answers from: pairing a `Router` with a
 * different declaration's registry (e.g. one built earlier and reused) would resolve the wrong
 * instance, or none at all, for anything given to `->middleware()`, `->filter()` or a handler as a real
 * instance.
 *
 * `->middleware()` on a group only ever runs for a request one of its own routes matches, since it's
 * baked into each of them. `->middleware()` on the root is different: it also wraps the not-found and
 * method-not-allowed/OPTIONS responses, so it's the way to run middleware — logging, CORS — for every
 * outcome, not just matched routes.
 */
final class Routes
{
    /** @var list<Route|self> */
    private array $items = [];

    /** @var list<mixed> */
    public array $middleware = [];

    /** @var list<string> */
    private array $tags = [];

    /**
     * @param string $prefix "" for no prefix (the root has none), otherwise starting with "/" and not
     *                       ending with "/"
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly string $prefix = '',
    ) {
        if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_ends_with($prefix, '/'))) {
            throw new InvalidRouteException(sprintf(
                'Group prefix "%s" must start with "/" and must not end with "/"; use "" for no prefix.',
                $prefix,
            ));
        }
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
        $group = new self($this->registry, $prefix);
        $this->items[] = $group;

        return $group;
    }

    /**
     * Adds middleware for every route declared on this scope, including nested groups, after the
     * middleware of any enclosing group. A class name or container identifier is resolved as usual; a
     * real instance or closure is wrapped into the tree's {@see Registry} instead, transparently.
     *
     * Declared on a group, this only ever runs for a request a route inside it actually matches — there
     * is no "wrong method" or "no route" response to decorate for a path the group doesn't own. Declared
     * on the root {@see RoutesHandlerBuilder::routes()} answers from, it also wraps the not-found and
     * method-not-allowed/OPTIONS responses: the one way to run middleware for every outcome, matched or
     * not, since {@see RoutesHandlerBuilder} takes no middleware of its own.
     *
     * @param mixed $middleware one middleware, or a list of them
     */
    public function middleware(mixed $middleware): self
    {
        $entries = is_array($middleware) ? array_values($middleware) : [$middleware];
        $owner = sprintf('%s middleware', $this->owner());
        // Middleware is arbitrary user data, so its entries are mixed by definition.
        // @mago-expect analysis:mixed-assignment
        foreach ($entries as $entry) {
            $this->middleware[] = $this->registry->wrap($entry, $owner);
        }

        return $this;
    }

    /**
     * Tags every route declared on this scope, including nested groups, in addition to the tags of
     * enclosing groups and the routes' own.
     */
    public function tag(string ...$tags): self
    {
        $this->tags = [...$this->tags, ...Route::validTags($this->owner(), $tags)];

        return $this;
    }

    /**
     * This tree as a {@see RouteTable}, cached under `$cacheKey` — `null` (the default) never caches
     * it, compiling on every request regardless of whether `Router` was given a cache. Pass something
     * that changes whenever these declarations would, e.g. an application version or a configuration
     * hash, for the caching described in {@see \SilenZ\Segmatch\Router} to actually take effect.
     */
    public function table(?string $cacheKey = null): RouteTable
    {
        return new CallableRouteTable($this->definitions(...), $cacheKey);
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
        $definitions = [];
        $names = [];

        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item->register($definitions, $this->prefix, [], $this->tags, $names);
                continue;
            }

            $definitions[] = $item->definition($this->prefix, [], $this->tags, $names);
        }

        return $definitions;
    }

    /**
     * This tree's {@see Registry}, shared by the root and every nested group: the one
     * {@see RoutesHandlerBuilder} resolves the ids standing in for real instances or closures in this
     * tree's metadata from. Rebuilt fresh every time this tree is declared, unlike the compiled routes —
     * so it belongs to this same, current declaration, never a cached one's.
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

            $routes[] = $item->definition($prefix, $middleware, $tags, $names);
        }
    }

    /**
     * This scope as error messages name it.
     */
    private function owner(): string
    {
        return $this->prefix === '' ? 'Routes without a prefix' : sprintf('Group "%s"', $this->prefix);
    }
}
