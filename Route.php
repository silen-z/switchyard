<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\RouteDefinition;

use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function is_subclass_of;
use function sprintf;
use function str_starts_with;

/**
 * One route declaration: HTTP methods, a path relative to the enclosing groups, and a handler.
 * Returned by {@see Routes} so it can be refined fluently:
 *
 *     $r->get('/users/{id}', [UserController::class, 'show'])
 *         ->name('users.show')
 *         ->middleware('audit')
 *         ->tag('public');
 *
 * The route's HTTP methods are stored with it directly; any filters added with `filter()` are stored
 * alongside them, in the order they were added. {@see RoutesHandlerBuilder} checks the methods and
 * resolves and runs the filters while matching.
 *
 * The handler, middleware and filters end up in the route cache, so they must be plain data (strings,
 * arrays, enums, ...), not closures or objects — a handler, a `middleware()` entry or a `filter()`
 * given as a real instance or closure is wrapped into the route's {@see Registry} instead,
 * transparently.
 */
final class Route
{
    private readonly mixed $handler;

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
        private readonly Registry $registry,
    ) {
        $this->handler = $this->registry->wrap($handler, sprintf('Route "%s" handler', $path));
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
     * Adds middleware that runs after the middleware of the enclosing groups. A class name or
     * container identifier is resolved as usual; a real instance or closure is wrapped into the
     * route's {@see Registry} instead, transparently.
     *
     * @param mixed $middleware one middleware, or a list of them
     */
    public function middleware(mixed $middleware): self
    {
        $entries = is_array($middleware) ? array_values($middleware) : [$middleware];
        // Middleware is arbitrary user data, so its entries are mixed by definition.
        // @mago-expect analysis:mixed-assignment
        foreach ($entries as $entry) {
            $this->middleware[] = $this->registry->wrap($entry, sprintf('Route "%s" middleware', $this->path));
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
     * configuration, or is built already configured, by the container resolving a class name or by you
     * giving an instance directly. An instance is wrapped into the route's {@see Registry}, a class
     * name resolved from the container by {@see RoutesHandlerBuilder}, transparently either way.
     *
     * @param string|RouteFilter $filter a class name implementing {@see RouteFilter}, or an instance
     *                                    of one
     */
    public function filter(string|RouteFilter $filter): self
    {
        if (is_string($filter) && !is_subclass_of($filter, RouteFilter::class)) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" uses filter "%s", which does not implement %s.',
                $this->path,
                $filter,
                RouteFilter::class,
            ));
        }

        $this->filters[] = $this->registry->wrap($filter, sprintf('Route "%s" filter', $this->path));

        return $this;
    }

    /**
     * Resolves this route into a core route definition: the full path (this route's own, under the
     * enclosing groups' prefix) and the metadata {@see RoutesHandlerBuilder} reads while matching:
     *
     *     [
     *         'handler'    => [UserController::class, 'show'],
     *         'middleware' => ['api', 'auth'],      // groups' middleware first, outermost first
     *         'name'       => 'users.show',         // only when named
     *         'path'       => '/api/users/{id}',    // only when named, for URL generation
     *         'tags'       => ['public'],           // only when tagged; groups' tags first, no duplicates
     *         'methods'    => ['GET'],              // only for routes with methods (not any())
     *         'filters'    => [FeatureRouteFilter::class], // only when there are any, checked in this order
     *     ]
     *
     * `handler` and each `middleware`/`filters` entry is a class name, a container identifier, or a
     * {@see Registry} id standing in for a real instance or closure.
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

        $metadata = [
            'handler' => $this->handler,
            'middleware' => [...$groupMiddleware, ...$this->middleware],
        ];

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

        return new RouteDefinition($fullPath, $metadata);
    }
}
