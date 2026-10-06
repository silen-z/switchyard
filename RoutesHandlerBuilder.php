<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;

use function array_keys;
use function array_push;
use function array_unshift;
use function is_array;
use function ltrim;
use function strtoupper;

/**
 * Answers HTTP requests by matching against an already-built {@see \SilenZ\Segmatch\Router} and
 * turning the result into a PSR-15 handler:
 *
 *     $routes = new Routes();
 *     $routes->get('/', HomeController::class);
 *
 *     $router = new Router($routes->table());
 *     $builder = new RoutesHandlerBuilder($container, $routes->registry(), $router);
 *
 *     $response = $builder->build($request)->handle($request);
 *
 * `$registry` must be the one the same declaration built `$router` with — {@see Routes} gives both
 * together, as above. Routes declared with {@see LazyRoutes} need none of their own; pass a fresh
 * `new Registry()` for an all-lazy router.
 */
final class RoutesHandlerBuilder
{
    private readonly Resolver $resolver;

    /**
     * @param ContainerInterface $container resolves everything a stack entry is named as — the
     *                                       route's middleware and handler, its {@see RouteFilter}s —
     *                                       plus this builder's own fallbacks: a PSR-17 response
     *                                       factory for {@see NotFoundHandler} and {@see
     *                                       AllowedMethodsHandler}, and a stream factory for {@see
     *                                       HeadMiddleware}. A container that autowires constructor
     *                                       arguments needs no registration of its own
     * @param Registry $registry the registry of the same declaration that built `$router`; a handler,
     *                           middleware entry or filter given as a real instance or closure is
     *                           looked up here instead of resolved from the container
     * @param Router $router already built, and ideally shared across requests — see
     *                       {@see \SilenZ\Segmatch\Router} for how it caches its own compiled routes
     */
    public function __construct(
        ContainerInterface $container,
        Registry $registry,
        private readonly Router $router,
    ) {
        $this->resolver = new Resolver($registry, $container);
    }

    /**
     * The PSR-15 handler for one request. For a matched route, that's its own middleware and handler
     * as one {@link https://relayphp.com/ Relay} stack — a `[target, 'method']` handler becomes a
     * {@see MethodHandler} — with the match on the request as `$request->getAttribute(Found::class)`.
     *
     * Otherwise one of three fallbacks, depending on why nothing matched:
     *
     * - no route for the path: {@see NotFoundHandler}, a 404, or `$notFoundHandler`;
     * - routes for the path, not the method: {@see AllowedMethodsHandler}, a 405 with `Allow`;
     * - the same for an OPTIONS request: {@see AllowedMethodsHandler}, a 200 with `Allow`.
     *
     * The latter two give `$request->getAttribute(MethodNotAllowed::class)`, counting only routes
     * rejected solely for their method (HEAD included whenever GET is).
     *
     * Either way, the root's own middleware wraps the result, outermost of all but {@see
     * HeadMiddleware} on a HEAD request — whoever answers, its response loses its body. HEAD also
     * matches a GET route unless one declared for HEAD itself applies.
     *
     * @param ?RequestHandlerInterface $notFoundHandler answers requests no route applies to, instead
     *                                                   of the container's {@see NotFoundHandler}
     */
    public function build(
        ServerRequestInterface $request,
        ?RequestHandlerInterface $notFoundHandler = null,
    ): RequestHandlerInterface {
        $match = $this->router->match(
            // The router wants the path to start with exactly one "/".
            '/' . ltrim($request->getUri()->getPath(), characters: '/'),
            fn(RouteMatch $candidate): bool => (
                MethodNotAllowed::accepts($candidate->route, $request->getMethod())
                && $this->accepts($candidate, $request)
            ),
        );

        // The root's own middleware wraps every outcome. It's kept as the table's metadata, so it's
        // read from the router — from the cache on a hit, like the routes — rather than from the tree.
        $queue = Routes::middlewareOf($this->router->tableMetadata());

        if ($match instanceof RouteMatch) {
            array_push($queue, ...$this->matched($match));
        } else {
            array_push($queue, ...$this->fallback(
                $match->rejected,
                $request,
                $notFoundHandler ?? NotFoundHandler::class,
            ));
        }

        if (strtoupper($request->getMethod()) === 'HEAD') {
            // Whoever answers, a HEAD response has no body.
            array_unshift($queue, HeadMiddleware::class);
        }

        return new Relay($queue, $this->resolver->entry(...));
    }

    /**
     * A matched route's Relay queue: its {@see Found} for {@see RouteContextMiddleware}, then the
     * route's own middleware (groups' first, outermost first) and handler, both as declared — a
     * `[target, 'method']` handler as a {@see MethodHandler}.
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
            ...$middleware,
            MethodHandler::wrap($this->resolver, $route['handler'] ?? null),
        ];
    }

    /**
     * The Relay queue for a request no route took, from the routes the router rejected for its path:
     *
     * - for a HEAD request, the queue of the first of them that accepts GET, as {@see matched()}.
     *   The router tried them in order, so it's the route a second pass for GET would find. The
     *   request isn't rewritten: filters and the route still see HEAD;
     * - otherwise a {@see MethodNotAllowed} of the methods of the routes whose filters accept (HEAD
     *   included whenever GET is), and {@see AllowedMethodsHandler};
     * - or, when no route's filters accept, the not-found handler.
     *
     * Routes without methods (`any()`) are skipped: rejected, so their filters failed.
     *
     * @param list<RouteMatch> $rejected
     *
     * @return non-empty-list<mixed>
     */
    private function fallback(
        array $rejected,
        ServerRequestInterface $request,
        RequestHandlerInterface|string $notFoundHandler,
    ): array {
        $method = strtoupper($request->getMethod());

        $allowed = [];
        foreach ($rejected as $candidate) {
            $methods = MethodNotAllowed::of($candidate->route);
            if ($methods === null || !$this->accepts($candidate, $request)) {
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
                AllowedMethodsHandler::class,
            ];
        }

        return [$notFoundHandler];
    }

    /**
     * Resolves and runs a route's own {@see RouteFilter}s, in the order they were added, each
     * resolved from the container the same way as middleware and handlers — the only place that
     * knows about the container, unlike {@see MethodNotAllowed}'s own method check. A route without
     * any filters always applies.
     */
    private function accepts(RouteMatch $match, ServerRequestInterface $request): bool
    {
        // The matched route's metadata is arbitrary user data, so it's mixed by definition.
        // @mago-expect analysis:mixed-assignment
        $route = $match->route;
        if (!is_array($route) || !is_array($route['filters'] ?? null)) {
            return true;
        }

        /** @var list<mixed> $filters */
        $filters = $route['filters'];

        // @mago-expect analysis:mixed-assignment
        foreach ($filters as $filter) {
            if (!$this->resolver->filter($filter)->accepts($match, $request)) {
                return false;
            }
        }

        return true;
    }
}
