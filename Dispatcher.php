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
use function is_int;
use function is_string;
use function ltrim;
use function strtoupper;

/**
 * Turns a request into the PSR-15 handler that answers it, for {@see RoutesHandlerBuilder::handler()},
 * which documents the behavior.
 *
 * `$router` and `$registry` must come from the same declaration — the registry is what the ids in the
 * router's metadata resolve against — which is why only {@see RoutesHandlerBuilder} builds one, from
 * the tree it owns.
 *
 * @internal
 */
final readonly class Dispatcher
{
    public function __construct(
        private Router $router,
        private Registry $registry,
        private ContainerInterface $container,
    ) {}

    public function handler(
        ServerRequestInterface $request,
        ?RequestHandlerInterface $notFoundHandler,
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
        $queue = RoutesTable::middlewareOf($this->router->tableMetadata());

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

        return new Relay($queue, $this->instantiate(...));
    }

    /**
     * A matched route's Relay queue: its {@see Found} for {@see RouteContextMiddleware}, then the
     * route's own middleware (groups' first, outermost first) and handler, both as declared.
     *
     * @return non-empty-list<mixed>
     */
    private function matched(RouteMatch $match): array
    {
        $route = is_array($match->route) ? $match->route : [];

        /** @var list<mixed> $middleware */
        $middleware = is_array($route['middleware'] ?? null) ? $route['middleware'] : [];

        return [new RouteContextMiddleware(Found::fromMatch($match)), ...$middleware, $route['handler'] ?? null];
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
     * Resolves and runs a route's own filters, in the order they were added. A route without any
     * always applies.
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
            /** @var RouteFilter $instance */
            $instance = $this->instantiate($filter);
            if (!$instance->accepts($match, $request)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One Relay queue entry as the real thing to run: a {@see Registry} id standing in for an instance
     * or closure the routes were declared with, a class name or container identifier for the
     * container to resolve, or anything else as itself.
     */
    private function instantiate(mixed $entry): mixed
    {
        if (is_int($entry)) {
            return $this->registry->get($entry);
        }

        if (!is_string($entry)) {
            return $entry;
        }

        return $this->container->get($entry);
    }
}
