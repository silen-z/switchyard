<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\InstanceRegistry;
use SilenZ\Segmatch\RouteDefinition;

use function array_unique;
use function array_values;
use function class_exists;
use function get_debug_type;
use function is_array;
use function is_string;
use function is_subclass_of;
use function sprintf;
use function str_starts_with;

/**
 * One route declaration for {@see LazyRoutes}: the same shape as {@see Route}, refined fluently the
 * same way, but for a tree that's only ever declared when the route cache has no entry. An instance or
 * closure given while declaring would not exist on the requests later answered from that cache, so
 * there is no {@see InstanceRegistry} here to wrap one into — a handler, middleware entry or filter must
 * already be a class name or container identifier instead, checked immediately by {@see plain()}
 * rather than at request time.
 *
 * PHP has no type for "a class name or container identifier", so the parameters below stay `mixed`;
 * their PHPDoc types are what's actually enforced, by static analysis rather than the engine.
 */
final class LazyRoute
{
    /** @var string|array{0: string, 1: string} */
    private readonly string|array $handler;

    private ?string $name = null;

    /** @var list<string> */
    private array $middleware = [];

    /** @var list<string> */
    private array $tags = [];

    /** @var list<string> */
    private array $filters = [];

    /**
     * @param ?non-empty-list<string> $methods upper-case HTTP methods, null for any method
     * @param string|array{0: string, 1: string} $handler a class name or container identifier, or
     *                                                     `[class name or container identifier, 'method']`
     *
     * @throws InvalidRouteException
     */
    public function __construct(
        private readonly ?array $methods,
        private readonly string $path,
        mixed $handler,
    ) {
        $owner = sprintf('Route "%s" handler', $path);
        MethodHandler::check($handler, $owner);
        $this->handler = self::plain($handler, $owner);
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
     * Adds middleware that runs after the middleware of the enclosing groups.
     *
     * @param string|list<string> $middleware one middleware, or a list of them, each a class name or
     *                                        container identifier
     */
    public function middleware(mixed $middleware): self
    {
        $entries = is_array($middleware) ? array_values($middleware) : [$middleware];
        $owner = sprintf('Route "%s" middleware', $this->path);
        // Middleware is arbitrary user data, so its entries are mixed by definition.
        // @mago-expect analysis:mixed-assignment
        foreach ($entries as $entry) {
            $this->middleware[] = self::plainString($entry, $owner);
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
        $this->tags = [...$this->tags, ...Route::validTags(sprintf('Route "%s"', $this->path), $tags)];

        return $this;
    }

    /**
     * Adds a condition of the application's own, checked in the order filters were added, after the
     * method check. May be called more than once. Any configuration a filter needs is a container
     * identifier of its own per configuration, e.g. `filter('feature.beta')` — a lazily declared route
     * can't take a ready instance, since it would not exist on the requests answered from the cache.
     *
     * @param string $filter a class name implementing {@see RouteFilter}, or a container identifier
     *                        resolving to one
     *
     * @throws InvalidRouteException for the name of an existing class that doesn't implement
     *                               {@see RouteFilter}; any other string is taken to be a container
     *                               identifier, checked once the container resolves it
     */
    public function filter(mixed $filter): self
    {
        $filter = self::plainString($filter, sprintf('Route "%s" filter', $this->path));

        if (class_exists($filter) && !is_subclass_of($filter, RouteFilter::class)) {
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
     * Resolves this route into a core route definition, the same shape {@see Route::definition()}
     * gives.
     *
     * @internal
     *
     * @param string $prefix the enclosing groups' prefix
     * @param list<string> $groupMiddleware the enclosing groups' middleware
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

    /**
     * $value as a class name or container identifier, or a `[string, string]` pair — both already
     * checked for shape by {@see MethodHandler::check()}, so an array here only needs its target
     * confirmed a string, never an instance.
     *
     * @return string|array{0: string, 1: string}
     *
     * @throws InvalidRouteException when $value, or an array's target, is an instance
     */
    private static function plain(mixed $value, string $owner): string|array
    {
        if (is_string($value) || is_array($value) && is_string($value[0])) {
            /** @var string|array{0: string, 1: string} $value */
            return $value;
        }

        throw self::notPlain($owner, $value);
    }

    /**
     * @throws InvalidRouteException when $value isn't a string
     */
    private static function plainString(mixed $value, string $owner): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw self::notPlain($owner, $value);
    }

    private static function notPlain(string $owner, mixed $value): InvalidRouteException
    {
        return new InvalidRouteException(sprintf(
            '%s must be a class name or a container identifier, not %s: lazily declared routes are only '
            . 'declared when their cache is built, so an instance would not exist on the requests answered '
            . 'from it. Register it in the container, or declare these routes eagerly.',
            $owner,
            get_debug_type($value),
        ));
    }
}
