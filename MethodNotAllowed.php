<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

/**
 * Routes exist for the request's path, but not for its method. {@see HandlerResolver::resolve()}
 * hands this to the method-not-allowed and OPTIONS handlers under the `MethodNotAllowed::class`
 * request attribute.
 */
final readonly class MethodNotAllowed
{
    /**
     * @param non-empty-list<string> $allowed the path's methods, HEAD included whenever GET is
     */
    public function __construct(
        public array $allowed,
    ) {}
}
