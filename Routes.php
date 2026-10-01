<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Closure;
use SilenZ\Segmatch\RouteDefinition;

/**
 * HTTP route definitions in a form {@see \SilenZ\Segmatch\Router} accepts as its `$routes` callable:
 *
 *     $router = new Router(Routes::define(static function (RouteCollector $r): void {
 *         $r->get('/', HomeController::class);
 *         $r->group('/api')->middleware('api')->define(static function (RouteCollector $r): void {
 *             $r->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
 *         });
 *     }));
 *
 * The definition callable may also be an invokable class, keeping route definitions out of the
 * bootstrap code. Like any route callback, it only runs when the router's cache has no entry.
 */
final readonly class Routes
{
    /**
     * @param Closure(RouteCollector): void $definition
     */
    private function __construct(
        private Closure $definition,
    ) {}

    /**
     * @param callable(RouteCollector): void $definition declares the routes, e.g. a closure or an invokable
     */
    public static function define(callable $definition): self
    {
        return new self($definition(...));
    }

    /**
     * Runs the definition and resolves it into core route definitions.
     *
     * @return list<RouteDefinition>
     */
    public function __invoke(): array
    {
        $collector = new RouteCollector();
        ($this->definition)($collector);

        $routes = [];
        $names = [];
        $collector->register($routes, '', [], $names);

        return $routes;
    }
}
