<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;

use function array_key_exists;
use function array_unique;
use function array_values;
use function is_array;
use function is_string;
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
 * alongside them, by class name or {@see Registry} id. {@see HandlerResolver} checks the methods and
 * resolves and runs the guards while matching.
 *
 * The handler, middleware and guard configuration end up in the route cache, so they must be plain
 * data (strings, arrays, enums, ...), not closures or objects — a handler, a `middleware()` entry or a
 * `guard()` given as a real instance or closure is wrapped into the route's {@see Registry} instead,
 * transparently; only guard *configuration* must still be plain data.
 */
final class Route
{
    private readonly mixed $handler;

    private ?string $name = null;

    /** @var list<mixed> */
    private array $middleware = [];

    /** @var list<string> */
    private array $tags = [];

    /** @var array<int|class-string<Guard>, mixed> */
    private array $guards = [];

    /** @var array<class-string<Guard>, true> guard classes already added, by class regardless of how */
    private array $guardClasses = [];

    /**
     * @param ?non-empty-list<string> $methods upper-case HTTP methods, null for any method
     */
    public function __construct(
        private readonly ?array $methods,
        private readonly string $path,
        mixed $handler,
        private readonly Registry $registry,
    ) {
        $this->handler = $this->registry->wrap($handler);
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
            $this->middleware[] = $this->registry->wrap($entry);
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
     * Adds a condition of the application's own, checked in the order guards were added, after the
     * method check. The configuration must be plain data. The guard itself is either a class name,
     * resolved by {@see HandlerResolver} from the container given to it (or built with a plain
     * `new $guard()` without one), or a ready {@see Guard} instance, wrapped into the route's
     * {@see Registry} instead, transparently.
     *
     * An instance usually has no need for `$config`: it can take its configuration as constructor
     * arguments instead, e.g. `guard(new FeatureGuard('beta'))` rather than
     * `guard(FeatureGuard::class, 'beta')`. `$config` still earns its keep for the class-name form,
     * where one guard class is shared across routes that each need it configured differently.
     *
     * @param string|Guard $guard a class name implementing {@see Guard}, or an instance of one
     */
    public function guard(string|Guard $guard, mixed $config = null): self
    {
        if (is_string($guard) && !is_subclass_of($guard, Guard::class)) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" uses guard "%s", which does not implement %s.',
                $this->path,
                $guard,
                Guard::class,
            ));
        }

        $class = is_string($guard) ? $guard : $guard::class;

        if (array_key_exists($class, $this->guardClasses)) {
            throw new InvalidRouteException(sprintf('Route "%s" uses guard "%s" twice.', $this->path, $class));
        }

        $this->guardClasses[$class] = true;

        /** @var int|class-string<Guard> $identifier */
        $identifier = $this->registry->wrap($guard);
        $this->guards[$identifier] = $config;

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
     *             FeatureGuard::class => 'beta',    // a guard given by class name keeps it as the key
     *             3 => null,                        // one given as an instance is a Registry id instead
     *         ],
     *     ]
     *
     * `handler` and each `middleware`/`guards` key is a class name, a container identifier, or a
     * {@see Registry} id standing in for a real instance or closure.
     *
     * @internal
     *
     * @param string $fullPath the route's path including the groups' prefixes
     * @param list<mixed> $groupMiddleware
     * @param list<string> $groupTags
     *
     * @return array{handler: mixed, middleware: list<mixed>, name?: string, path?: string, tags?: non-empty-list<string>, methods?: non-empty-list<string>, guards?: non-empty-array<int|class-string<Guard>, mixed>}
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
