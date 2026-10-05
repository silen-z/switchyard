<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;

use function array_keys;
use function array_unshift;
use function is_array;
use function is_string;
use function ltrim;
use function strtoupper;

/**
 * Builds the PSR-15 handler that answers an HTTP request, from routes declared with {@see Routes}:
 *
 *     $resolver = new HandlerResolver($router, $responseFactory, $container);
 *     $response = $resolver->resolve($request)->handle($request);
 *
 * For a matched route, {@see resolve()} gives one {@link https://relayphp.com/ Relay} stack of the
 * route's own middleware and handler. Inside it, `$request->getAttribute(Found::class)` gives the
 * route's parameters, name and tags.
 *
 * Otherwise it gives one of three handlers:
 *
 * - no route for the path: {@see NotFoundHandler}, a 404, or the not-found handler given to the
 *   constructor;
 * - routes for the path, but not for the method: {@see AllowedMethodsHandler}, a 405 with the
 *   `Allow` header;
 * - the same for an OPTIONS request: {@see AllowedMethodsHandler}, a 200 with the `Allow` header.
 *
 * The latter two give middleware the allowed methods as `$request->getAttribute(MethodNotAllowed::class)`.
 *
 * Middleware added with {@see addMiddleware()} wraps whichever of these answers. That's how to change
 * the 405 and OPTIONS answers, e.g. for CORS preflights: middleware sees the attribute, and can
 * decorate the response or answer itself.
 *
 * Matching checks a route's own HTTP methods ({@see MethodNotAllowed}, container-free) and runs its
 * {@see Guard}s, each resolved by {@see instantiate()} the same way as middleware and handlers. The
 * container is only ever known here, not by {@see MethodNotAllowed} or {@see Guard} itself.
 *
 * HEAD requests match GET routes unless a route for HEAD itself applies. Whoever answers a HEAD
 * request, the response loses its body ({@see HeadMiddleware}).
 */
final class HandlerResolver
{
    private readonly RequestHandlerInterface $notFoundHandler;

    /** @var list<string|MiddlewareInterface> */
    private array $middleware = [];

    /**
     * @param ResponseFactoryInterface $responseFactory builds the default 404, and the 405 and
     *                                                  OPTIONS responses, and the empty body of HEAD
     *                                                  responses
     * @param ?RequestHandlerInterface $notFoundHandler answers requests that no route applies to,
     *                                                  instead of {@see NotFoundHandler}
     */
    public function __construct(
        private readonly Router $router,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly ?ContainerInterface $container = null,
        ?RequestHandlerInterface $notFoundHandler = null,
    ) {
        $this->notFoundHandler = $notFoundHandler ?? new NotFoundHandler($responseFactory);
    }

    /**
     * Middleware for every request, whatever answers it: a route, or the not-found,
     * method-not-allowed or OPTIONS handler. It runs after matching, outside the route's own
     * middleware, and sees the `Found::class` or `MethodNotAllowed::class` request attribute (neither
     * for a 404). Identifiers are resolved like route middleware; calls add to the end, so the first
     * added is outermost.
     *
     * Middleware that must run before matching, e.g. to set attributes guards read, belongs in a
     * stack around {@see resolve()} instead.
     */
    public function addMiddleware(string|MiddlewareInterface ...$middleware): void
    {
        $this->middleware = [...$this->middleware, ...$middleware];
    }

    /**
     * The handler that answers the request. For a matched route, that's the route's own middleware
     * and then its handler, as one PSR-15 stack ({@see \Relay\Relay}). The handler and each
     * middleware entry are resolved by {@see instantiate()} and must come out as a
     * `Psr\Http\Server\MiddlewareInterface` (middleware) or `Psr\Http\Server\RequestHandlerInterface`
     * (the handler).
     *
     * Otherwise it's the not-found, method-not-allowed or OPTIONS handler. Allowed methods count only
     * routes rejected solely because of their method; HEAD is included whenever GET is.
     */
    public function resolve(ServerRequestInterface $request): RequestHandlerInterface
    {
        $match = $this->router->match(
            // The router wants the path to start with exactly one "/".
            '/' . ltrim($request->getUri()->getPath(), characters: '/'),
            fn(mixed $route, array $params): bool => (
                MethodNotAllowed::accepts($route, $request->getMethod())
                && $this->guardsAccept($route, $request, $params)
            ),
        );

        $queue = $match instanceof RouteMatch ? $this->matched($match) : $this->fallback($match->rejected, $request);

        if (strtoupper($request->getMethod()) === 'HEAD') {
            // Whoever answers, a HEAD response has no body.
            array_unshift($queue, new HeadMiddleware($this->responseFactory));
        }

        return new Relay($queue, $this->instantiate(...));
    }

    /**
     * A matched route's Relay queue: its {@see Found} for {@see RouteContextMiddleware}, the
     * application middleware, then the route's own middleware (groups' first, outermost first) and
     * handler, both as declared.
     *
     * @return non-empty-list<mixed>
     */
    private function matched(RouteMatch $match): array
    {
        $route = is_array($match->route) ? $match->route : [];

        /** @var list<mixed> $middleware */
        $middleware = is_array($route['middleware'] ?? null) ? $route['middleware'] : [];

        return [
            new RouteContextMiddleware(Found::fromMatch($match)),
            ...$this->middleware,
            ...$middleware,
            $route['handler'] ?? null,
        ];
    }

    /**
     * The Relay queue for a request no route took, from the routes the router rejected for its path:
     *
     * - for a HEAD request, the queue of the first of them that accepts GET, as {@see matched()}.
     *   The router tried them in order, so it's the route a second pass for GET would find. The
     *   request isn't rewritten: guards and the route still see HEAD;
     * - otherwise a {@see MethodNotAllowed} of the methods of the routes whose guards accept (HEAD
     *   included whenever GET is), the application middleware, and {@see AllowedMethodsHandler};
     * - or, when no route's guards accept, the application middleware and the not-found handler.
     *
     * Routes without methods (`any()`) are skipped: rejected, so their guards failed.
     *
     * @param list<RouteMatch> $rejected
     *
     * @return non-empty-list<mixed>
     */
    private function fallback(array $rejected, ServerRequestInterface $request): array
    {
        $method = strtoupper($request->getMethod());

        $allowed = [];
        foreach ($rejected as $candidate) {
            $methods = MethodNotAllowed::of($candidate->route);
            if ($methods === null || !$this->guardsAccept($candidate->route, $request, $candidate->params)) {
                continue;
            }

            if ($method === 'HEAD' && MethodNotAllowed::accepts($candidate->route, 'GET')) {
                return $this->matched($candidate);
            }

            foreach ($methods as $allowedMethod) {
                $allowed[$allowedMethod] = true;
            }
        }

        if ($allowed['GET'] ?? false) {
            $allowed['HEAD'] = true;
        }

        $allowed = array_keys($allowed);
        if ($allowed !== []) {
            return [
                new RouteContextMiddleware(new MethodNotAllowed($allowed)),
                ...$this->middleware,
                new AllowedMethodsHandler($this->responseFactory),
            ];
        }

        return [...$this->middleware, $this->notFoundHandler];
    }

    /**
     * Resolves a guard, middleware or handler identifier to an instance: from the container given
     * to this constructor, or a plain `new $entry()` without one. The container is only ever known
     * here, never by {@see Guard} or the PSR-15 middleware and handlers {@see resolve()} builds.
     * Relay passes every stack entry through here, so anything already resolved is returned as is.
     */
    private function instantiate(mixed $entry): mixed
    {
        if (!is_string($entry)) {
            return $entry;
        }

        return $this->container?->get($entry) ?? new $entry();
    }

    /**
     * Resolves and runs a route's own guards, in the order they were added. A route without any
     * always applies.
     *
     * @param array<string, string> $params
     */
    private function guardsAccept(mixed $route, ServerRequestInterface $request, array $params): bool
    {
        if (!is_array($route) || !is_array($route['guards'] ?? null)) {
            return true;
        }

        /** @var array<class-string<Guard>, mixed> $guards */
        $guards = $route['guards'];

        // @mago-expect analysis:mixed-assignment
        foreach ($guards as $guard => $config) {
            /** @var Guard $instance */
            $instance = $this->instantiate($guard);
            if (!$instance->accepts($config, $request, $params)) {
                return false;
            }
        }

        return true;
    }
}
