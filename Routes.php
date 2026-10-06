<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Closure;
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
 *     $routes = new Routes();
 *     $routes->get('/', HomeController::class);
 *     $routes->group('/api')->middleware('api')->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
 *
 *     $router = new Router($routes->table('routes-' . APP_VERSION), cache: new FileCache($dir));
 *     $builder = new RoutesHandlerBuilder($container, $routes->registry(), $router);
 *
 * Declaring runs immediately, like any other PHP code; there is nothing to defer. A `group()` is
 * itself a `Routes`, scoped by an optional path prefix, with its own middleware and tags inherited by
 * everything declared on it (including further nested groups).
 *
 * A handler, middleware entry or filter may be a real instance or closure, not just a class name or
 * container identifier: anything that isn't already cacheable plain data is transparently wrapped into
 * this tree's {@see Registry} instead, shared by the root and every nested group — see {@see
 * registry()}. {@see LazyRoutes} declares lazily instead, when even declaring on every request costs
 * too much; its handler, middleware and filters may only be a class name or container identifier.
 */
final class Routes
{
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
        private readonly Registry $registry = new Registry(),
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
     * on the root instead — the tree whose {@see table()} built the `Router` a `RoutesHandlerBuilder`
     * answers from — it also wraps the not-found and method-not-allowed/OPTIONS responses: the one way
     * to run middleware for every outcome, matched or not, since `RoutesHandlerBuilder` takes no
     * middleware of its own.
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
     *
     * The table's metadata ({@see RouteTable::metadata()}) is this scope's own middleware,
     * `['middleware' => [...]]`, which {@see definitions()} bakes into no route; read it back with
     * {@see middlewareOf()}.
     */
    public function table(?string $cacheKey = null): RouteTable
    {
        return self::tableOf(fn(): self => $this, $cacheKey);
    }

    /**
     * The root's middleware out of a table's metadata as {@see table()} gave it, e.g. read back with
     * {@see \SilenZ\Segmatch\Router::tableMetadata()}; none for anything else.
     *
     * @internal for {@see RoutesHandlerBuilder}
     *
     * @return list<mixed>
     */
    public static function middlewareOf(mixed $tableMetadata): array
    {
        if (!is_array($tableMetadata) || !is_array($tableMetadata['middleware'] ?? null)) {
            return [];
        }

        return array_values($tableMetadata['middleware']);
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
     * This tree's {@see Registry}, shared by the root and every nested group: the one a {@see
     * RoutesHandlerBuilder} resolves a real instance or closure's id from. Rebuilt fresh every time
     * this tree is declared, unlike the compiled routes, so it only ever matches this same, current
     * declaration — pass it to `RoutesHandlerBuilder` alongside the `Router` built from this same
     * tree's {@see table()}, never a different declaration's.
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
     * A table whose definitions are a tree's routes and whose metadata is the root's own middleware,
     * which wraps every outcome rather than being baked into a route. `$tree` is called at most once,
     * and only when either is needed — on a cache miss — so both come from the same tree.
     *
     * @param Closure(): self $tree
     */
    private static function tableOf(Closure $tree, ?string $cacheKey): RouteTable
    {
        /** @var ?self $routes set by $once, through the reference it captures */
        $routes = null;
        $once = static function () use ($tree, &$routes): self {
            return $routes ??= $tree();
        };

        return new RouteTable(static fn(): array => $once()->definitions(), $cacheKey, static fn(): array => [
            'middleware' => $once()->middleware,
        ]);
    }

    /**
     * This scope as error messages name it.
     */
    private function owner(): string
    {
        return $this->prefix === '' ? 'Routes without a prefix' : sprintf('Group "%s"', $this->prefix);
    }
}
