<?php

declare(strict_types=1);

namespace SilenZ\Switchyard;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;
use SilenZ\Beeline\RouteMatch;
use SilenZ\Beeline\Router;

use function array_keys;
use function array_push;
use function array_unshift;
use function is_array;
use function ltrim;
use function str_ends_with;
use function strtoupper;
use function substr;

/**
 * Answers HTTP requests by matching against an already-built {@see \SilenZ\Beeline\Router} and
 * turning the result into a PSR-15 handler:
 *
 *     $routes = new Routes();
 *     $routes->get('/', HomeController::class);
 *
 *     $router = new Router($routes->table());
 *     $builder = new HandlerBuilder($container, $router);
 *
 *     $response = $builder->build($request)->handle($request);
 *
 * A handler, middleware entry or filter declared as a real instance or closure reaches here already
 * resolved: {@see \SilenZ\Beeline\Matcher} resolves it out of the table's own
 * {@see \SilenZ\Beeline\MetadataRegistry} (if it has one) before `$router->match()` ever returns, so
 * this builder's own {@see Resolver} only ever has to deal with class names and container identifiers.
 */
final class HandlerBuilder
{
    private readonly Resolver $resolver;

    /**
     * @param ContainerInterface $container resolves everything a stack entry is named as — the
     *                                       route's middleware and handler, its {@see RouteFilter}s,
     *                                       {@see Routes::notFound()} if it replaces the default —
     *                                       plus this builder's own fallbacks: a PSR-17 response
     *                                       factory for {@see NotFoundHandler}, {@see
     *                                       AllowedMethodsHandler} and this builder's own trailing-slash
     *                                       {@see RedirectHandler}, and a stream factory for {@see
     *                                       HeadMiddleware} and {@see ErrorMiddleware} (which also
     *                                       needs the response factory). A container that autowires
     *                                       constructor arguments needs no registration of its own
     * @param Router $router already built, and ideally shared across requests — see
     *                       {@see \SilenZ\Beeline\Router} for how it caches its own compiled routes
     */
    public function __construct(
        ContainerInterface $container,
        private readonly Router $router,
    ) {
        $this->resolver = new Resolver($container);
    }

    /**
     * The PSR-15 handler for one request. For a matched route, that's its own middleware and handler
     * as one {@link https://relayphp.com/ Relay} stack — with the match on the request as
     * `Found::fromRequest($request)`.
     *
     * Otherwise one of four fallbacks, depending on why nothing matched:
     *
     * - no route for the exact path, but one exists with its trailing "/" added or removed: a 308 to
     *   that path via {@see RedirectHandler};
     * - no route for the path at all (nor, if looked for, a trailing-slash counterpart): {@see
     *   NotFoundHandler}, a 404, or whatever {@see Routes::notFound()} replaced it with;
     * - routes for the path, not the method: {@see AllowedMethodsHandler}, a 405 with `Allow`;
     * - the same for an OPTIONS request: {@see AllowedMethodsHandler}, a 200 with `Allow`.
     *
     * The latter two give `MethodNotAllowed::fromRequest($request)`, counting only routes
     * rejected solely for their method (HEAD included whenever GET is).
     *
     * Either way, {@see ErrorMiddleware} wraps everything else, including the root's own middleware —
     * turning whatever any of it throws into a 500 instead of letting it reach this method's caller —
     * outermost of all but {@see HeadMiddleware} on a HEAD request, whose body-stripping applies to an
     * error response too. Unlike {@see Routes::notFound()}, this default isn't replaceable: it's
     * always the outermost entry, so add your own error-catching middleware via
     * {@see Routes::middleware()} instead (declared first) to actually change the effective behavior —
     * see there for why that's equivalent, not just a workaround.
     *
     * The not-found replacement is declared on {@see Routes}/{@see LazyRoutes}, not passed here:
     * whoever declares the routes may not be whoever builds this `HandlerBuilder` (e.g. a framework
     * exposing `Routes` to its own users while keeping this call to itself), so there's nothing left
     * for a caller of `build()` itself to override.
     */
    public function build(ServerRequestInterface $request): RequestHandlerInterface
    {
        // The router wants the path to start with exactly one "/".
        $path = '/' . ltrim($request->getUri()->getPath(), characters: '/');
        $filter = fn(RouteMatch $candidate): bool => (
            MethodNotAllowed::accepts($candidate->route, $request->getMethod()) && $this->accepts($candidate, $request)
        );

        $match = $this->router->match($path, $filter);

        $stack = [ErrorMiddleware::class, ...$this->globalMiddleware()];

        if ($match instanceof RouteMatch) {
            array_push($stack, ...$this->matched($match));
        } else {
            $redirect = $match->rejected === [] ? $this->trailingSlash($path, $filter, $request) : null;

            if ($redirect !== null) {
                $stack[] = $redirect;
            } else {
                array_push($stack, ...$this->fallback($match->rejected, $request, $this->notFound()));
            }
        }

        if (strtoupper($request->getMethod()) === 'HEAD') {
            // Whoever answers, a HEAD response has no body.
            array_unshift($stack, HeadMiddleware::class);
        }

        return new Relay($stack, $this->resolver->entry(...));
    }

    /**
     * A matched route's Relay queue: its {@see Found} for {@see RouteContextMiddleware}, then the
     * route's own middleware (groups' first, outermost first) and handler, both exactly as declared —
     * or, for a route built by {@see Routes::redirect()}, a {@see RedirectHandler} built fresh from its
     * `'redirect'` metadata instead of resolved from `'handler'` at all.
     *
     * @return non-empty-list<mixed>
     */
    private function matched(RouteMatch $match): array
    {
        $route = is_array($match->route) ? $match->route : [];

        /** @var list<mixed> $middleware */
        $middleware = is_array($route['middleware'] ?? null) ? $route['middleware'] : [];

        /** @var array{location: string, status: int}|null $redirect */
        $redirect = is_array($route['redirect'] ?? null) ? $route['redirect'] : null;
        // The matched route's metadata is arbitrary user data, so it's mixed by definition.
        $handler = $redirect !== null
            ? new RedirectHandler($this->resolver->responseFactory(), $redirect['location'], $redirect['status'])
            : $route['handler'] ?? null;

        return [
            new RouteContextMiddleware(Found::fromMatch($match)),
            ...$middleware,
            $handler,
        ];
    }

    /**
     * A {@see RedirectHandler} to `$path`'s trailing-slash counterpart ("/foo/" for "/foo" or vice
     * versa), when one exists and accepts `$filter` — the same one `$path` itself was just tried
     * with — or, for a HEAD request, when {@see headFallback()} would still answer it from there, same
     * as it would for `$path` itself. Either way the redirect only ever points somewhere this request
     * would actually be answered, not merely somewhere a route happens to exist. `null` when there's
     * nothing to redirect to, including for `$path` itself being "/", which has no counterpart to
     * toggle.
     *
     * Only ever reached for a `$path` with no route of its own ({@see build()} only calls this when
     * {@see \SilenZ\Beeline\NoMatch::$rejected} came back empty), so an existing route always takes
     * precedence over redirecting to another one. For `$path` itself being "/", toggling it yields ""
     * — not a path `Router::match()` could ever have a route for, so it, too, naturally falls through
     * to `null` below, with no special case needed for it here.
     *
     * @param callable(RouteMatch): bool $filter
     */
    private function trailingSlash(string $path, callable $filter, ServerRequestInterface $request): ?RedirectHandler
    {
        $toggled = str_ends_with($path, '/') ? substr($path, offset: 0, length: -1) : $path . '/';
        $retry = $this->router->match($toggled, $filter);

        if (!$retry instanceof RouteMatch && $this->headFallback($retry->rejected, $request) === null) {
            return null;
        }

        $query = $request->getUri()->getQuery();
        $location = $query === '' ? $toggled : $toggled . '?' . $query;

        return new RedirectHandler($this->resolver->responseFactory(), $location);
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
    private function fallback(array $rejected, ServerRequestInterface $request, mixed $notFoundHandler): array
    {
        $head = $this->headFallback($rejected, $request);
        if ($head !== null) {
            return $this->matched($head);
        }

        $allowed = [];
        foreach ($rejected as $candidate) {
            $methods = MethodNotAllowed::of($candidate->route);
            if ($methods === null || !$this->accepts($candidate, $request)) {
                continue;
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
     * For a HEAD request, the first of `$rejected` that accepts GET and whose own filters accept —
     * the route a second pass for GET would find, since the router already tried every candidate of
     * the path in declaration order. `null` for any other method, or when none of them do.
     *
     * @param list<RouteMatch> $rejected
     */
    private function headFallback(array $rejected, ServerRequestInterface $request): ?RouteMatch
    {
        if (strtoupper($request->getMethod()) !== 'HEAD') {
            return null;
        }

        foreach ($rejected as $candidate) {
            if (
                MethodNotAllowed::accepts($candidate->route, 'GET')
                && MethodNotAllowed::of($candidate->route) !== null
                && $this->accepts($candidate, $request)
            ) {
                return $candidate;
            }
        }

        return null;
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
        $route = $match->route;
        if (!is_array($route) || !is_array($route['filters'] ?? null)) {
            return true;
        }

        /** @var list<mixed> $filters */
        $filters = $route['filters'];

        foreach ($filters as $filter) {
            if (!$this->resolver->filter($filter)->accepts($match, $request)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The root's own middleware, from {@see Router::metadata()} — see {@see metadataValue()}.
     */
    private function globalMiddleware(): array
    {
        // The table's own metadata is arbitrary user data, so it's mixed by definition.
        $middleware = $this->metadataValue('middleware');

        return is_array($middleware) ? array_values($middleware) : [];
    }

    /**
     * {@see Routes::notFound()}'s replacement, from {@see Router::metadata()} — see
     * {@see metadataValue()} — or {@see NotFoundHandler} if it was never called. Already resolved by
     * {@see \SilenZ\Beeline\Matcher} if it was given a {@see \SilenZ\Beeline\MetadataRegistry} to
     * resolve it from, so this is pushed onto the Relay stack as-is — a class name or container
     * identifier for {@see Resolver::entry()} to resolve when the request actually reaches it, or
     * already the real instance.
     */
    private function notFound(): mixed
    {
        return $this->metadataValue('notFound') ?? NotFoundHandler::class;
    }

    /**
     * One key of the table's own metadata, read through {@see Router::metadata()}, not
     * {@see Router::table()}: it comes from the cache on a hit, same as the routes, where the table's
     * own {@see RouteTable::metadata()} would re-run the metadata closure (and, for {@see LazyRoutes},
     * declare the whole tree again) on every single request regardless of the cache. `null` if the
     * table's metadata isn't the `array` {@see Routes::table()}/{@see LazyRoutes::table()} produce, or
     * has nothing under `$key`.
     */
    private function metadataValue(string $key): mixed
    {
        // The table's own metadata is arbitrary user data, so it's mixed by definition.
        $metadata = $this->router->metadata();

        return is_array($metadata) ? $metadata[$key] ?? null : null;
    }
}
