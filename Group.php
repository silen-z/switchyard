<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Closure;
use SilenZ\Segmatch\Exception\InvalidRouteException;

use function array_values;
use function is_array;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

/**
 * A group of routes sharing an optional path prefix and middleware. Returned by
 * {@see RouteCollector::group()} and configured fluently:
 *
 *     $r->group('/admin')
 *         ->middleware(['auth', 'admin'])
 *         ->routes(static function (RouteCollector $r): void {
 *             $r->get('/stats', [AdminController::class, 'stats']);
 *         });
 *
 * Groups are resolved after all routes have been declared, so the order of the calls doesn't matter.
 */
final class Group
{
    /** @var list<mixed> */
    private array $middleware = [];

    /** @var list<Closure(RouteCollector): void> */
    private array $definitions = [];

    /**
     * @param string $prefix "" for no prefix, otherwise starting with "/" and not ending with "/"
     */
    public function __construct(
        private readonly string $prefix = '',
    ) {
        if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_ends_with($prefix, '/'))) {
            throw new InvalidRouteException(sprintf(
                'Group prefix "%s" must start with "/" and must not end with "/"; use "" for no prefix.',
                $prefix,
            ));
        }
    }

    /**
     * Adds middleware for every route in the group, after the middleware of enclosing groups.
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
     * Declares the group's routes. May be called more than once.
     *
     * @param callable(RouteCollector): void $define
     */
    public function routes(callable $define): self
    {
        $this->definitions[] = $define(...);

        return $this;
    }

    /**
     * @internal
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * @internal
     *
     * @return list<mixed>
     */
    public function groupMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Runs the route definitions into a fresh collector for this group.
     *
     * @internal
     */
    public function collect(): RouteCollector
    {
        $collector = new RouteCollector();
        foreach ($this->definitions as $define) {
            $define($collector);
        }

        return $collector;
    }
}
