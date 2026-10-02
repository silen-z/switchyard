<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\RouteMatch;

/**
 * Result of {@see Dispatcher::match()}: a route applies to the request.
 */
final readonly class Found
{
    /**
     * @param mixed $handler the route's handler, as declared
     * @param array<string, string> $params URL-decoded parameter values by name
     * @param list<mixed> $middleware groups' middleware first, outermost first, then the route's own
     * @param ?non-empty-list<string> $methods the route's HTTP methods, null for `any()` routes
     * @param list<string> $tags the route's tags, groups' tags first
     */
    public function __construct(
        public mixed $handler,
        public array $params = [],
        public array $middleware = [],
        public ?string $name = null,
        public ?array $methods = null,
        public array $tags = [],
    ) {}

    public static function fromMatch(RouteMatch $match): Found
    {
        $route = is_array($match->route) ? $match->route : [];

        /** @var list<mixed> $middleware */
        $middleware = is_array($route['middleware'] ?? null) ? $route['middleware'] : [];
        /** @var ?non-empty-list<string> $methods */
        $methods = is_array($route['methods'] ?? null) ? $route['methods'] : null;
        /** @var list<string> $tags */
        $tags = is_array($route['tags'] ?? null) ? $route['tags'] : [];

        return new Found(
            handler: $route['handler'] ?? null,
            params: $match->params,
            middleware: $middleware,
            name: is_string($route['name'] ?? null) ? $route['name'] : null,
            methods: $methods,
            tags: $tags,
        );
    }
}
