<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Attribute;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Switchyard\Handler;

use function in_array;
use function is_array;
use function strtoupper;

/**
 * Routes exist for the request's path, but not for its method. {@see Handler::build()}
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

    /**
     * The `MethodNotAllowed` {@see Handler::build()} put on the request, null unless it's
     * being answered by the method-not-allowed or OPTIONS handler.
     */
    public static function fromRequest(ServerRequestInterface $request): ?MethodNotAllowed
    {
        $methodNotAllowed = $request->getAttribute(self::class);

        return $methodNotAllowed instanceof self ? $methodNotAllowed : null;
    }

    /**
     * Whether the route accepts the given HTTP method (case-insensitive). True for a route without
     * methods (`any()`).
     */
    public static function accepts(mixed $route, string $method): bool
    {
        $methods = self::of($route);

        return $methods === null || in_array(strtoupper($method), $methods, strict: true);
    }

    /**
     * The route's own HTTP methods, null when it has none (it accepts any method).
     *
     * @return ?list<string>
     */
    public static function of(mixed $route): ?array
    {
        if (!is_array($route) || !is_array($route['methods'] ?? null)) {
            return null;
        }

        /** @var list<string> */
        return $route['methods'];
    }
}
