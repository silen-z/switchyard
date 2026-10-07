<?php

declare(strict_types=1);

namespace SilenZ\Switchyard;

use SilenZ\Beeline\Exception\InvalidRouteException;
use SilenZ\Beeline\MetadataRegistry;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Switchyard\Handler\RedirectHandler;

use function array_unique;
use function array_values;
use function class_exists;
use function is_array;
use function is_string;
use function is_subclass_of;
use function sprintf;
use function str_starts_with;

/**
 * One route declaration: HTTP methods, a path relative to the enclosing groups, and a handler.
 * Returned by {@see Routes} so it can be refined fluently:
 *
 *     $r->get('/users/{id}', ShowUser::class)
 *         ->name('users.show')
 *         ->middleware('audit')
 *         ->tag('public');
 *
 * {@see HandlerBuilder} checks the route's own HTTP methods and resolves and runs its filters,
 * in the order `filter()` added them, while matching.
 */
final class Route
{
    private readonly mixed $handler;

    /** @var ?array{location: string, status: int} */
    private ?array $redirect = null;

    private ?string $name = null;

    /** @var list<mixed> */
    private array $middleware = [];

    /** @var list<string> */
    private array $tags = [];

    /** @var list<mixed> */
    private array $filters = [];

    /**
     * @param ?non-empty-list<string> $methods upper-case HTTP methods, null for any method
     */
    public function __construct(
        private readonly ?array $methods,
        private readonly string $path,
        mixed $handler,
        private readonly MetadataRegistry $registry,
    ) {
        $this->handler = $handler;
    }

    /**
     * @internal set by {@see Routes::redirect()}, replacing the placeholder handler its constructor
     *           call needed in the route's own metadata — {@see HandlerBuilder} checks for this first
     */
    public function asRedirect(string $location, int $status): self
    {
        $this->redirect = ['location' => $location, 'status' => $status];

        return $this;
    }

    /**
     * Names the route. Names are unique across all routes.
     */
    public function name(string $name): self
    {
        if ($name === '') {
            throw new InvalidRouteException(sprintf('Route "%s" cannot have an empty name.', $this->path));
        }

        $this->name = $name;

        return $this;
    }

    /**
     * Adds middleware that runs after the middleware of the enclosing groups. A class name, container
     * identifier, real instance or closure are all kept as given — see {@see Routes::$registry}.
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
     * Labels the route, e.g. `->tag('public')` for a global auth middleware to let through. The
     * router stores tags in the metadata and never interprets them; tags of enclosing groups are
     * inherited.
     */
    public function tag(string ...$tags): self
    {
        $this->tags = [...$this->tags, ...self::validTags(sprintf('Route "%s"', $this->path), $tags)];

        return $this;
    }

    /**
     * @internal shared with {@see Routes::tag()}
     *
     * @param array<array-key, string> $tags
     *
     * @return list<string>
     */
    public static function validTags(string $owner, array $tags): array
    {
        foreach ($tags as $tag) {
            if ($tag === '') {
                throw new InvalidRouteException(sprintf('%s cannot have an empty tag.', $owner));
            }
        }

        return array_values($tags);
    }

    /**
     * Adds a condition of the application's own, checked in the order filters were added, after the
     * method check. May be called more than once, including with the same filter class: each instance
     * is checked independently, which is how to vary one filter's behavior within a single route, e.g.
     * `filter(new NumericRouteFilter('id'))->filter(new NumericRouteFilter('postId'))`.
     *
     * Any configuration a filter needs is a constructor argument of its own, e.g.
     * `filter(new FeatureRouteFilter('beta'))`, not a separate parameter here: a filter either takes no
     * configuration, or is built already configured, by the container resolving a class name or
     * identifier, or by you giving an instance directly — kept as given either way, transparently. A
     * container identifier per configuration, e.g. `filter('feature.beta')`, is how routes declared
     * lazily, which can't take instances, vary a filter per route.
     *
     * @param string|RouteFilter $filter a class name implementing {@see RouteFilter}, a container
     *                                    identifier resolving to one, or an instance of one
     *
     * @throws InvalidRouteException for the name of an existing class that doesn't implement
     *                               {@see RouteFilter}; any other string is taken to be a container
     *                               identifier, checked once the container resolves it
     */
    public function filter(string|RouteFilter $filter): self
    {
        if (is_string($filter) && class_exists($filter) && !is_subclass_of($filter, RouteFilter::class)) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" uses filter "%s", which does not implement %s.',
                $this->path,
                $filter,
                RouteFilter::class,
            ));
        }

        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Resolves this route into a core route definition: the full path (this route's own, under the
     * enclosing groups' prefix) and the metadata {@see HandlerBuilder} reads while matching:
     *
     *     [
     *         'handler'    => ShowUser::class,
     *         'middleware' => ['api', 'auth'],      // groups' middleware first, outermost first
     *         'name'       => 'users.show',         // only when named
     *         'path'       => '/api/users/{id}',    // only when named, for URL generation
     *         'tags'       => ['public'],           // only when tagged; groups' tags first, no duplicates
     *         'methods'    => ['GET'],              // only for routes with methods (not any())
     *         'filters'    => [FeatureRouteFilter::class], // only when there are any, checked in this order
     *     ]
     *
     * `handler` and each `middleware`/`filters` entry may be a class name, a container identifier, a
     * real instance or a closure — kept exactly as given. A route built by {@see Routes::redirect()}
     * gets `'redirect' => ['location' => ..., 'status' => ...]` instead of `handler` — plain data, so
     * {@see HandlerBuilder} builds its {@see RedirectHandler} directly from it, fresh per request.
     *
     * This whole array is handed to {@see MetadataRegistry::register()} as one unit — the returned id
     * is what actually becomes the `RouteDefinition`'s metadata, so it survives a round trip through a
     * compiled cache file regardless of what the metadata above actually holds.
     *
     * @internal
     *
     * @param string $prefix the enclosing groups' prefix
     * @param list<mixed> $groupMiddleware the enclosing groups' middleware
     * @param list<string> $groupTags the enclosing groups' tags
     * @param array<string, string> $names route name => path of the routes registered so far, checked
     *                                     and added to for this one
     *
     * @throws InvalidRouteException
     */
    public function definition(string $prefix, array $groupMiddleware, array $groupTags, array &$names): RouteDefinition
    {
        // Inside a group with a prefix, "" declares a route on the prefix itself.
        if (!($this->path === '' && $prefix !== '') && !str_starts_with($this->path, '/')) {
            throw new InvalidRouteException(sprintf('Route path "%s" must start with "/".', $this->path));
        }

        $fullPath = $prefix . $this->path;

        if ($this->name !== null) {
            if (($names[$this->name] ?? null) !== null) {
                throw new InvalidRouteException(sprintf(
                    'Route name "%s" is used by both "%s" and "%s".',
                    $this->name,
                    $names[$this->name],
                    $fullPath,
                ));
            }

            $names[$this->name] = $fullPath;
        }

        $metadata = $this->redirect !== null ? ['redirect' => $this->redirect] : ['handler' => $this->handler];
        $metadata['middleware'] = [...$groupMiddleware, ...$this->middleware];

        if ($this->name !== null) {
            $metadata['name'] = $this->name;
            $metadata['path'] = $fullPath;
        }

        $tags = array_values(array_unique([...$groupTags, ...$this->tags]));
        if ($tags !== []) {
            $metadata['tags'] = $tags;
        }

        if ($this->methods !== null) {
            $metadata['methods'] = $this->methods;
        }

        if ($this->filters !== []) {
            $metadata['filters'] = $this->filters;
        }

        return new RouteDefinition($fullPath, $this->registry->register($metadata));
    }
}
