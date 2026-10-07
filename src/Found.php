<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\RouteMatch;

use function is_array;
use function is_string;

/**
 * The route that applies to the request, as {@see HandlerBuilder::build()} hands it to the
 * route's own middleware and handler under the `Found::class` request attribute.
 */
final readonly class Found
{
    /**
     * @param array<string, string> $params URL-decoded parameter values by name
     * @param list<string> $tags the route's tags, groups' tags first
     */
    public function __construct(
        public array $params = [],
        public ?string $name = null,
        public array $tags = [],
    ) {}

    public static function fromMatch(RouteMatch $match): Found
    {
        $route = is_array($match->route) ? $match->route : [];

        /** @var list<string> $tags */
        $tags = is_array($route['tags'] ?? null) ? $route['tags'] : [];

        return new Found(
            params: $match->params,
            name: is_string($route['name'] ?? null) ? $route['name'] : null,
            tags: $tags,
        );
    }
}
