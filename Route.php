<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;

use function array_key_exists;
use function array_unique;
use function array_values;
use function is_array;
use function is_subclass_of;
use function sprintf;

/**
 * One route declaration: HTTP methods, a path relative to the enclosing groups, and a handler.
 * Returned by {@see Routes} so it can be refined fluently:
 *
 *     $r->get('/users/{id}', [UserController::class, 'show'])
 *         ->name('users.show')
 *         ->middleware('audit')
 *         ->tag('public');
 *
 * The route's HTTP methods are stored with it directly; any guards added with `guard()` are stored
 * alongside them, by class name. {@see Dispatcher} checks the methods and resolves and runs the
 * guards while matching.
 *
 * The handler, middleware and guard configuration end up in the route cache, so they must be plain
 * data (strings, arrays, enums, ...), not closures or objects.
 */
final class Route
{
    private ?string $name = null;

    /** @var list<mixed> */
    private array $middleware = [];

    /** @var list<string> */
    private array $tags = [];

    /** @var array<class-string<Guard>, mixed> */
    private array $guards = [];

    /**
     * @param ?non-empty-list<string> $methods upper-case HTTP methods, null for any method
     */
    public function __construct(
        private readonly ?array $methods,
        private readonly string $path,
        private readonly mixed $handler,
    ) {}

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
     * @param mixed $middleware one middleware, or a list of them
     */
    public function middleware(mixed $middleware): self
    {
        $this->middleware = [
            ...$this->middleware,
            ...(is_array($middleware) ? array_values($middleware) : [$middleware]),
        ];

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
     * @internal shared with {@see Group::tag()}
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
     * Adds a condition of the application's own, checked in the order guards were added, after the
     * method check. The configuration must be plain data; the guard itself is resolved by
     * {@see Dispatcher} from the container given to it (or built with a plain `new $guard()`
     * without one).
     *
     * @param string $guard name of a class implementing {@see Guard}
     */
    public function guard(string $guard, mixed $config = null): self
    {
        if (!is_subclass_of($guard, Guard::class)) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" uses guard "%s", which does not implement %s.',
                $this->path,
                $guard,
                Guard::class,
            ));
        }

        if (array_key_exists($guard, $this->guards)) {
            throw new InvalidRouteException(sprintf('Route "%s" uses guard "%s" twice.', $this->path, $guard));
        }

        $this->guards[$guard] = $config;

        return $this;
    }

    /**
     * @internal
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @internal
     */
    public function routeName(): ?string
    {
        return $this->name;
    }

    /**
     * The metadata stored for the route:
     *
     *     [
     *         'handler'    => [UserController::class, 'show'],
     *         'middleware' => ['api', 'auth'],     // groups' middleware first, outermost first
     *         'name'       => 'users.show',        // only when named
     *         'path'       => '/api/users/{id}',   // only when named, for URL generation
     *         'tags'       => ['public'],          // only when tagged; groups' tags first, no duplicates
     *         'methods'    => ['GET'],             // only for routes with methods (not any())
     *         'guards'     => [                    // only when there are any, checked in this order
     *             FeatureGuard::class => 'beta',
     *         ],
     *     ]
     *
     * @internal
     *
     * @param string $fullPath the route's path including the groups' prefixes
     * @param list<mixed> $groupMiddleware
     * @param list<string> $groupTags
     *
     * @return array{handler: mixed, middleware: list<mixed>, name?: string, path?: string, tags?: non-empty-list<string>, methods?: non-empty-list<string>, guards?: non-empty-array<class-string<Guard>, mixed>}
     */
    public function metadata(string $fullPath, array $groupMiddleware, array $groupTags): array
    {
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

        if ($this->guards !== []) {
            $metadata['guards'] = $this->guards;
        }

        return $metadata;
    }
}
