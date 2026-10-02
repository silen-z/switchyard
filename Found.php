<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

/**
 * Result of {@see Dispatcher::dispatch()}: a route applies to the request.
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
}
