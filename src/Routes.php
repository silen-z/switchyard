<?php

declare(strict_types=1);

namespace SilenZ\Switchyard;

use SilenZ\Beeline\Exception\InvalidRouteException;
use SilenZ\Beeline\MetadataRegistry;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\Router;
use SilenZ\Beeline\RouteTable;
use SilenZ\Switchyard\Handler\NotFoundHandler;
use SilenZ\Switchyard\Handler\RedirectHandler;
use SilenZ\Switchyard\Middleware\ErrorMiddleware;

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
 *     $routes->group('/api')->middleware('api')->get('/users/{id}', ShowUser::class)->name('users.show');
 *
 *     $router = new Router($routes->table('routes-' . APP_VERSION), cache: new FileCache($dir));
 *     $handler = new Handler($container, $router);
 *
 * Declaring runs immediately, like any other PHP code; there is nothing to defer. A `group()` is
 * itself a `Routes`, scoped by an optional path prefix, with its own middleware and tags inherited by
 * everything declared on it (including further nested groups).
 *
 * A handler, middleware entry or filter may be a real instance or closure, not just a class name or
 * container identifier, since none of it is ever cached directly: every route's full metadata is
 * handed to this tree's {@see MetadataRegistry} as one unit, by {@see Route::definition()}, and a
 * `Router` built from {@see table()} resolves it back through the same registry while matching — see
 * {@see MetadataRegistry} for why that's safe. {@see LazyRoutes} declares lazily instead, when even
 * declaring on every request costs too much; its handler, middleware and filters may only be a class
 * name or container identifier, precisely because it has no registry to fall back on.
 *
 * {@see notFound()} lets whoever declares the routes — not just whoever builds the `Handler`
 * — replace its default not-found handler, e.g. a framework exposing this tree to its own users while
 * configuring its own `Handler` internally. There's no equivalent for the default error
 * middleware: it's always the outermost entry, so anything declared with {@see middleware()} instead
 * sits further in and catches first — see {@see middleware()}.
 */
final class Routes
{
    /** @var list<Route|self> */
    private array $items = [];

    /** @var list<mixed> */
    private array $middleware = [];

    /** @var list<string> */
    private array $tags = [];

    private mixed $notFound = null;

    /**
     * @param string $prefix "" for no prefix (the root has none), otherwise starting with "/" and not
     *                       ending with "/"
     * @param ?self $root the tree's actual root, `null` if this instance is it — see {@see group()}
     */
    public function __construct(
        private readonly MetadataRegistry $registry = new MetadataRegistry(),
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
     * A GET route answering with a {@see RedirectHandler} to `$location`, 308 by default — permanent,
     * preserving the method and body, unlike a 301/302:
     *
     *     $routes->redirect('/old', '/new');
     *
     * Unlike every other handler, `$location`/`$status` are already plain data, so
     * {@see Handler} builds the `RedirectHandler` itself, fresh per request, straight from the
     * route's own metadata.
     */
    public function redirect(string $path, string $location, int $status = 308): Route
    {
        return $this->get($path, RedirectHandler::class)->asRedirect($location, $status);
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
     * groups never share middleware or tags. Shares this tree's {@see MetadataRegistry}.
     */
    public function group(string $prefix = ''): self
    {
        $group = new self($this->registry, $prefix, $this->root());
        $this->items[] = $group;

        return $group;
    }

    /**
     * Adds middleware for every route declared on this scope, including nested groups, after the
     * middleware of any enclosing group. A class name, container identifier, real instance or closure
     * are all kept as given — see {@see MetadataRegistry}.
     *
     * Declared on a group, this only ever runs for a request a route inside it actually matches — there
     * is no "wrong method" or "no route" response to decorate for a path the group doesn't own. Declared
     * on the root instead — the tree whose {@see table()} built the `Router` a `Handler`
     * answers from — it also wraps the not-found and method-not-allowed/OPTIONS responses: the one way
     * to declare middleware for every outcome, matched or not. {@see Handler}'s own default
     * {@see ErrorMiddleware} wraps this root middleware too, so a throw from it still becomes a 500
     * rather than reaching `build()`'s caller — there's deliberately no way to replace that default:
     * add your own error-catching middleware here instead, declared first so it still wraps every
     * other root middleware while itself sitting inside the default. An exception reaches the
     * innermost catch first, so yours answers it and the default further out never sees a throwable
     * at all — harmless, not a conflict.
     *
     * @param mixed $middleware one middleware, or a list of them
     */
    public function middleware(mixed $middleware): self
    {
        $entries = is_array($middleware) ? array_values($middleware) : [$middleware];
        // Middleware is arbitrary user data, so its entries are mixed by definition.
        foreach ($entries as $entry) {
            $this->middleware[] = $entry;
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
     * Replaces {@see Handler}'s default {@see NotFoundHandler} for this tree: the answer to a
     * request no route takes at all (not merely the wrong method). A class name, container identifier,
     * real instance or closure are all kept as given — see {@see MetadataRegistry}.
     *
     * Only the root may set this — there's one not-found handler for the whole table, never one per
     * group, so calling this anywhere else would silently reconfigure the root instead of scoping to
     * where it was called, which {@see InvalidRouteException} catches instead.
     *
     * @throws InvalidRouteException when called on anything but the root
     */
    public function notFound(mixed $handler): self
    {
        if ($this->root !== null) {
            throw new InvalidRouteException('Only the root Routes may set the not-found handler, not a nested group.');
        }

        $this->notFound = $handler;

        return $this;
    }

    /**
     * This tree as a {@see RouteTable}, cached under `$cacheKey` — `null` (the default) still caches
     * it, under whatever the given {@see \SilenZ\Beeline\Cache\RouteCache} treats as its own default for
     * a `null` key (only `Router` being given no cache at all compiles on every request instead). Pass
     * something that changes whenever these declarations would, e.g. an application version or a
     * configuration hash, so a deploy or a config change doesn't keep serving a stale compiled table —
     * see {@see \SilenZ\Beeline\Router}.
     *
     * The table's own metadata ({@see RouteTable::metadata()}) — this scope's root middleware and
     * not-found handler — is handed to this tree's {@see MetadataRegistry} as one unit, the same way
     * {@see Route::definition()} does for each route; {@see Handler::build()} reads it back
     * through {@see Router::metadata()}. {@see registry()} travels with the table too, so a `Router`
     * built from it is always paired with the same declaration's registry.
     */
    public function table(?string $cacheKey = null): RouteTable
    {
        return new RouteTable(
            $this->definitions(...),
            $cacheKey,
            fn(): int => $this->registry->register(['middleware' => $this->middleware, 'notFound' => $this->notFound]),
            $this->registry,
        );
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
     * This tree's {@see MetadataRegistry}, shared by the root and every nested group — carried
     * automatically into {@see table()}'s result, so mainly useful directly for inspecting what a
     * declaration handed it, e.g. in a test.
     */
    public function registry(): MetadataRegistry
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
