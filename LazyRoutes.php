<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\InstanceRegistry;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\RouteTable;

use function array_unique;
use function array_values;
use function get_debug_type;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strtoupper;

/**
 * HTTP route declarations, for a tree that's only ever declared lazily: `$define` gets a fresh tree
 * and only runs when the route cache has no entry, so a request answered from the cache declares
 * nothing at all.
 *
 *     $router = new Router(LazyRoutes::table(static function (LazyRoutes $routes): void {
 *         $routes->get('/', HomeController::class);
 *         $routes->group('/api')->middleware('api')->get('/users/{id}', [UserController::class, 'show']);
 *     }, 'routes-' . APP_VERSION), cache: new FileCache($dir));
 *
 *     $builder = new HandlerBuilder($container, $router);
 *     $response = $builder->build($request)->handle($request);
 *
 * The price of declaring lazily: every handler, middleware entry and filter must be a class name or
 * container identifier — an instance or closure would only exist on the request that built the cache,
 * so `$define` throws {@see InvalidRouteException} the moment it declares one. In exchange, nothing
 * this tree declares is ever wrapped for later lookup, so there's never anything to resolve out of
 * `$router->table()->registry()` — the empty {@see InstanceRegistry} {@see RouteTable} defaults to is
 * all an all-lazy router ever needs.
 *
 * Otherwise this is {@see Routes}, shaped the same way: verb helpers, `group()`, `->middleware()` and
 * `->tag()` accumulate only once the tree is resolved into definitions. {@see notFoundHandler()} and
 * {@see errorMiddleware()} work the same way they do there too — see {@see Routes} for why they exist.
 */
final class LazyRoutes
{
    /** @var list<LazyRoute|self> */
    private array $items = [];

    /** @var list<string> */
    private array $middleware = [];

    /** @var list<string> */
    private array $tags = [];

    private ?string $notFoundHandler = null;

    private ?string $errorMiddleware = null;

    /**
     * @param string $prefix "" for no prefix (the root has none), otherwise starting with "/" and not
     *                       ending with "/"
     * @param ?self $root the tree's actual root, `null` if this instance is it — see {@see group()}
     */
    public function __construct(
        private readonly string $prefix = '',
        private readonly ?self $root = null,
    ) {
        if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_ends_with($prefix, '/'))) {
            throw new InvalidRouteException(sprintf(
                'Group prefix "%s" must start with "/" and must not end with "/"; use "" for no prefix.',
                $prefix,
            ));
        }
    }

    /**
     * @param string|array{0: string, 1: string} $handler
     */
    public function get(string $path, mixed $handler): LazyRoute
    {
        return $this->map(['GET'], $path, $handler);
    }

    /**
     * @param string|array{0: string, 1: string} $handler
     */
    public function post(string $path, mixed $handler): LazyRoute
    {
        return $this->map(['POST'], $path, $handler);
    }

    /**
     * @param string|array{0: string, 1: string} $handler
     */
    public function put(string $path, mixed $handler): LazyRoute
    {
        return $this->map(['PUT'], $path, $handler);
    }

    /**
     * @param string|array{0: string, 1: string} $handler
     */
    public function patch(string $path, mixed $handler): LazyRoute
    {
        return $this->map(['PATCH'], $path, $handler);
    }

    /**
     * @param string|array{0: string, 1: string} $handler
     */
    public function delete(string $path, mixed $handler): LazyRoute
    {
        return $this->map(['DELETE'], $path, $handler);
    }

    /**
     * @param string|array{0: string, 1: string} $handler
     */
    public function options(string $path, mixed $handler): LazyRoute
    {
        return $this->map(['OPTIONS'], $path, $handler);
    }

    /**
     * A route for every HTTP method (it gets no 'methods' metadata, so it's never method-checked).
     *
     * @param string|array{0: string, 1: string} $handler
     */
    public function any(string $path, mixed $handler): LazyRoute
    {
        $route = new LazyRoute(null, $path, $handler);
        $this->items[] = $route;

        return $route;
    }

    /**
     * A route for the given HTTP methods (case-insensitive).
     *
     * @param list<string> $methods
     * @param string|array{0: string, 1: string} $handler
     */
    public function map(array $methods, string $path, mixed $handler): LazyRoute
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

        $route = new LazyRoute(array_values(array_unique($normalized)), $path, $handler);
        $this->items[] = $route;

        return $route;
    }

    /**
     * A group of routes with an optional path prefix, e.g. `$r->group('/admin')->middleware('auth')->get(...)`.
     * May be called more than once with the same prefix; each call adds a separate group, so sibling
     * groups never share middleware or tags.
     */
    public function group(string $prefix = ''): self
    {
        $group = new self($prefix, $this->root());
        $this->items[] = $group;

        return $group;
    }

    /**
     * Adds middleware for every route declared on this scope, including nested groups, after the
     * middleware of any enclosing group.
     *
     * Declared on the root {@see HandlerBuilder} answers from, it also wraps the not-found and
     * method-not-allowed/OPTIONS responses — see {@see Routes::middleware()}.
     *
     * @param string|list<string> $middleware one middleware, or a list of them, each a class name or
     *                                        container identifier
     */
    public function middleware(mixed $middleware): self
    {
        $entries = is_array($middleware) ? array_values($middleware) : [$middleware];
        $owner = sprintf('%s middleware', $this->owner());
        foreach ($entries as $entry) {
            $this->middleware[] = self::plainString($entry, $owner);
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
     * Replaces {@see HandlerBuilder}'s default {@see NotFoundHandler} for this tree: the answer to a
     * request no route takes at all (not merely the wrong method). A class name or container
     * identifier, same restriction as {@see middleware()}.
     *
     * Only the root may set this — there's one not-found handler for the whole table, never one per
     * group, so calling this anywhere else would silently reconfigure the root instead of scoping to
     * where it was called, which {@see InvalidRouteException} catches instead.
     *
     * @throws InvalidRouteException when called on anything but the root, or $handler isn't a string
     */
    public function notFoundHandler(mixed $handler): self
    {
        if ($this->root !== null) {
            throw new InvalidRouteException('Only the root Routes may set the not-found handler, not a nested group.');
        }

        $this->notFoundHandler = self::plainString($handler, 'The not-found handler');

        return $this;
    }

    /**
     * Replaces {@see HandlerBuilder}'s default {@see ErrorMiddleware} for this tree: what wraps every
     * outcome — matched or not — turning a throw into a response instead of letting it reach
     * `build()`'s caller. A class name or container identifier, same restriction as {@see middleware()}.
     *
     * Only the root may set this, for the same reason as {@see notFoundHandler()}.
     *
     * @throws InvalidRouteException when called on anything but the root, or $middleware isn't a string
     */
    public function errorMiddleware(mixed $middleware): self
    {
        if ($this->root !== null) {
            throw new InvalidRouteException('Only the root Routes may set the error middleware, not a nested group.');
        }

        $this->errorMiddleware = self::plainString($middleware, 'The error middleware');

        return $this;
    }

    /**
     * A {@see RouteTable} for a tree built by `$define`, cached under `$cacheKey` — `null` (the
     * default) never caches it. `$define` is only called once, and only when the table's definitions
     * or metadata are actually needed, i.e. on a cache miss, so a request answered from the cache
     * declares nothing at all.
     *
     * @param callable(self): void $define
     */
    public static function table(callable $define, ?string $cacheKey = null): RouteTable
    {
        /** @var ?self $routes set by $once, through the reference it captures */
        $routes = null;
        $once = static function () use ($define, &$routes): self {
            if ($routes === null) {
                $routes = new self();
                $define($routes);
            }

            return $routes;
        };

        return new RouteTable(static fn(): array => $once()->definitions(), $cacheKey, static fn(): array => [
            'middleware' => $once()->middleware,
            'notFoundHandler' => $once()->notFoundHandler,
            'errorMiddleware' => $once()->errorMiddleware,
        ]);
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
     * Resolves the declared routes into core route definitions, groups recursively.
     *
     * @internal
     *
     * @param list<RouteDefinition> $routes the resolved routes, appended to
     * @param string $prefix the enclosing groups' prefix
     * @param list<string> $middleware the enclosing groups' middleware
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
     * @throws InvalidRouteException when $value isn't a string
     */
    private static function plainString(mixed $value, string $owner): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new InvalidRouteException(sprintf(
            '%s must be a class name or a container identifier, not %s: lazily declared routes are only '
            . 'declared when their cache is built, so an instance would not exist on the requests answered '
            . 'from it. Register it in the container, or declare these routes eagerly.',
            $owner,
            get_debug_type($value),
        ));
    }

    /**
     * This scope as error messages name it.
     */
    private function owner(): string
    {
        return $this->prefix === '' ? 'Routes without a prefix' : sprintf('Group "%s"', $this->prefix);
    }

    /**
     * The tree's actual root, itself if {@see $root} is `null` — flattened at construction time
     * ({@see group()} passes its own already-resolved root along), so this is never more than one hop
     * away regardless of nesting depth.
     */
    private function root(): self
    {
        return $this->root ?? $this;
    }
}
