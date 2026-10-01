<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Closure;
use SilenZ\Segmatch\RouteSet;

/**
 * HTTP route definitions in a form {@see \SilenZ\Segmatch\Router} accepts as its `$routes` callable:
 *
 *     $router = new Router(new Routes(static function (RouteCollector $r): void {
 *         $r->get('/', HomeController::class);
 *         $r->group('/api')->middleware('api')->routes(static function (RouteCollector $r): void {
 *             $r->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
 *         });
 *     }));
 *
 * The definition callable may also be an invokable class, keeping route definitions out of the
 * bootstrap code. Like any route callback, it only runs when the router's cache has no entry.
 */
final readonly class Routes
{
    /** @var Closure(RouteCollector): void */
    private Closure $define;

    /**
     * @param callable(RouteCollector): void $define declares the routes
     */
    public function __construct(callable $define)
    {
        $this->define = $define(...);
    }

    public function __invoke(RouteSet $routes): void
    {
        $collector = new RouteCollector();
        ($this->define)($collector);

        $names = [];
        $collector->register($routes, '', [], $names);
    }
}
