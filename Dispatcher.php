<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;

use function array_search;
use function array_splice;
use function in_array;
use function is_array;
use function is_string;

/**
 * Dispatches HTTP requests to routes declared with {@see Routes}:
 *
 *     $dispatcher = new Dispatcher($router);
 *     $result = $dispatcher->dispatch($method, $path);
 *
 *     match (true) {
 *         $result instanceof Found => ...,            // $result->handler, ->params, ->middleware
 *         $result instanceof MethodNotAllowed => ..., // 405, Allow: implode(', ', $result->allowed)
 *         $result instanceof NotFound => ...,         // 404
 *     };
 *
 * HEAD requests match GET routes unless a route for HEAD itself applies. OPTIONS isn't routed
 * automatically; {@see allowedMethods()} gives what an OPTIONS or CORS response needs.
 */
final readonly class Dispatcher
{
    public function __construct(
        private Router $router,
    ) {}

    /**
     * @param Request|string $request the request, or just its HTTP method
     * @param string $path request path without query string, starting with "/"
     */
    public function dispatch(Request|string $request, string $path): Found|MethodNotAllowed|NotFound
    {
        $request = $request instanceof Request ? $request : new Request($request);

        $result = $this->router->match($path, Guards::for($request));
        if ($result instanceof NoMatch && $request->method === 'HEAD') {
            $get = $this->router->match($path, Guards::for(new Request('GET', $request->attributes)));
            if ($get instanceof RouteMatch) {
                $result = $get;
            }
        }

        if ($result instanceof RouteMatch) {
            return self::found($result);
        }

        $allowed = self::withHead(Guards::allowedMethods($result, $request));

        return $allowed === [] ? new NotFound() : new MethodNotAllowed($allowed);
    }

    /**
     * The HTTP methods the path supports, e.g. `['GET', 'HEAD', 'PUT']`, with HEAD wherever GET is.
     * Routes declared with `any()` aren't listed, and routes whose own guards reject the request
     * don't count. Guards see an OPTIONS request with the given attributes.
     *
     * @param array<string, mixed> $attributes see {@see Request::$attributes}
     *
     * @return list<string>
     */
    public function allowedMethods(string $path, array $attributes = []): array
    {
        // Rejecting every route collects all of the path's candidates.
        $result = $this->router->match($path, static fn(): bool => false);
        if (!$result instanceof NoMatch) {
            return [];
        }

        return self::withHead(Guards::allowedMethods($result, new Request('OPTIONS', $attributes)));
    }

    private static function found(RouteMatch $match): Found
    {
        $route = is_array($match->route) ? $match->route : [];
        $guards = is_array($route['guards'] ?? null) ? $route['guards'] : [];

        /** @var list<mixed> $middleware */
        $middleware = is_array($route['middleware'] ?? null) ? $route['middleware'] : [];
        /** @var ?non-empty-list<string> $methods */
        $methods = is_array($guards[MethodGuard::class] ?? null) ? $guards[MethodGuard::class] : null;
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

    /**
     * Adds HEAD right after GET, since HEAD requests match GET routes.
     *
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private static function withHead(array $methods): array
    {
        $get = array_search('GET', $methods, strict: true);
        if ($get !== false && !in_array('HEAD', $methods, strict: true)) {
            array_splice($methods, $get + 1, length: 0, replacement: ['HEAD']);
        }

        return $methods;
    }
}
