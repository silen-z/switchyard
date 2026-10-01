<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;

use function array_key_exists;
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
 *         ->middleware('audit');
 *
 * The route's conditions become {@see Guard}s stored with it: its HTTP methods a {@see MethodGuard},
 * plus any guards added with `guard()`. Matching with {@see Guards::for()} runs them.
 *
 * The handler, middleware and guard configuration end up in the route cache, so they must be plain
 * data (strings, arrays, enums, ...), not closures or objects.
 */
final class Route
{
    private ?string $name = null;

    /** @var list<mixed> */
    private array $middleware = [];

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
     * Adds a condition of the application's own, checked in the order guards were added, after the
     * method check. The configuration must be plain data.
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

        if ($guard === MethodGuard::class) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" cannot add %s directly; declare the route with its HTTP methods instead.',
                $this->path,
                $guard,
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
     *         'guards'     => [                    // only when there are any, checked in this order
     *             MethodGuard::class => ['GET'],
     *         ],
     *     ]
     *
     * @internal
     *
     * @param list<mixed> $groupMiddleware
     *
     * @return array{handler: mixed, middleware: list<mixed>, name?: string, guards?: non-empty-array<class-string<Guard>, mixed>}
     */
    public function metadata(array $groupMiddleware): array
    {
        $metadata = [
            'handler' => $this->handler,
            'middleware' => [...$groupMiddleware, ...$this->middleware],
        ];

        if ($this->name !== null) {
            $metadata['name'] = $this->name;
        }

        $guards = $this->methods !== null ? [MethodGuard::class => $this->methods] : [];
        $guards += $this->guards;
        if ($guards !== []) {
            $metadata['guards'] = $guards;
        }

        return $metadata;
    }
}
