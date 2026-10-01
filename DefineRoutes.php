<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Closure;
use SilenZ\Segmatch\RouteDefinition;

/**
 * The route callable returned by {@see Routes::define()}: when the router needs its routes, it runs
 * the definition against a fresh {@see Routes} and resolves the declarations into core
 * {@see RouteDefinition}s. Like any route callable, it only runs when the router's cache has no entry.
 */
final readonly class DefineRoutes
{
    /**
     * @internal use {@see Routes::define()}
     *
     * @param Closure(Routes): void $definition
     */
    public function __construct(
        private Closure $definition,
    ) {}

    /**
     * @return list<RouteDefinition>
     */
    public function __invoke(): array
    {
        $routes = new Routes();
        ($this->definition)($routes);

        $definitions = [];
        $names = [];
        $routes->register($definitions, '', [], [], $names);

        return $definitions;
    }
}
