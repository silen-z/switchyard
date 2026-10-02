<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;
use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;

use function array_keys;
use function implode;
use function is_array;
use function ltrim;
use function strtoupper;

/**
 * Dispatches HTTP requests to routes declared with {@see Routes}:
 *
 *     $dispatcher = new Dispatcher($router, $container, $responseFactory);
 *     $result = $dispatcher->match($request);
 *
 *     match (true) {
 *         $result instanceof Found => ...,            // $result->handler, ->params, ->middleware
 *         $result instanceof MethodNotAllowed => ..., // 405, Allow: implode(', ', $result->allowed)
 *         $result instanceof NotFound => ...,         // 404
 *     };
 *
 * Matching checks a route's own HTTP methods ({@see Methods}, container-free) and runs its
 * {@see Guard}s, each resolved by {@see resolve()} the same way an application resolves
 * `$result->handler`. The container is only ever known here, not by {@see Methods} or {@see Guard}
 * itself.
 *
 * HEAD requests match GET routes unless a route for HEAD itself applies. OPTIONS isn't routed
 * automatically; {@see allowedMethods()} gives what an OPTIONS or CORS response needs.
 *
 * {@see handle()} goes one step further: it dispatches and then runs the route's middleware and
 * handler itself, as a PSR-15 stack built with {@link https://relayphp.com/ Relay}. That makes this
 * dispatcher itself a `RequestHandlerInterface`, so it can be used as the terminal entry of another
 * PSR-15 stack (an application-wide middleware queue, a framework's fallback handler, ...).
 */
final readonly class Dispatcher implements RequestHandlerInterface
{
    public function __construct(
        private Router $router,
        private ?ContainerInterface $container = null,
        private ?ResponseFactoryInterface $responseFactory = null,
    ) {}

    /**
     * Dispatches the request and runs it all the way through to a response: the route's own
     * middleware, then its handler, as one PSR-15 stack ({@see \Relay\Relay}). Unlike {@see match()},
     * the handler and each middleware entry aren't left opaque — they're resolved by {@see resolve()}
     * and must come out as a `Psr\Http\Server\MiddlewareInterface` (middleware) or
     * `Psr\Http\Server\RequestHandlerInterface` (the handler). A `[Class::class, 'method']` handler
     * doesn't fit this; use {@see match()} and your own pipeline for that instead.
     *
     * The `$responseFactory` given to this dispatcher's constructor builds the 404 and 405
     * responses; a `MethodNotAllowed` adds the `Allow` header to it.
     *
     * @throws LogicException if this dispatcher was built without a `$responseFactory`
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->match($request);

        if ($result instanceof NotFound) {
            return $this->responseFactory()->createResponse(404);
        }

        if ($result instanceof MethodNotAllowed) {
            return $this->responseFactory()
                ->createResponse(405)
                ->withHeader('Allow', implode(', ', $result->allowed));
        }

        foreach ($result->params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return new Relay([...$result->middleware, $result->handler], $this->resolve(...))->handle($request);
    }

    public function match(ServerRequestInterface $request): Found|MethodNotAllowed|NotFound
    {
        $path = self::normalizedPath($request);

        $match = $this->router->match($path, fn(mixed $route, array $params): bool =>
            Methods::accepts($route, $request->getMethod())
            && $this->guardsAccept($route, $request, $params));

        if ($match instanceof NoMatch && strtoupper($request->getMethod()) === 'HEAD') {
            $get = $this->router->match($path, fn(mixed $route, array $params): bool =>
                Methods::accepts($route, "GET")
                && $this->guardsAccept($route, $request->withMethod('GET'), $params));

            if ($get instanceof RouteMatch) {
                $match = $get;
            }
        }

        if ($match instanceof RouteMatch) {
            return Found::fromMatch($match);
        }

        $allowed = $this->allowed($match->rejected, $request);

        return $allowed === [] ? new NotFound() : new MethodNotAllowed($allowed);
    }

    /**
     * The HTTP methods the path supports, e.g. `['GET', 'PUT', 'HEAD']` — HEAD is included whenever
     * GET is, in no particular position. Routes declared with `any()` aren't listed, and routes whose
     * own guards reject the request don't count. Guards see the given request with its method
     * replaced by OPTIONS.
     *
     * @return list<string>
     */
    public function allowedMethods(ServerRequestInterface $request): array
    {
        $matches = $this->router->matchAll(self::normalizedPath($request));

        return $this->allowed($matches, $request->withMethod('OPTIONS'));
    }

    /**
     * Resolves a guard, middleware or handler identifier to an instance: from the container given
     * to this constructor, or a plain `new $entry()` without one. The container is only ever known
     * here, never by {@see Guard} or the PSR-15 middleware and handlers {@see handle()} runs.
     */
    private function resolve(string $entry): mixed
    {
        return $this->container?->get($entry) ?? new $entry();
    }

    private function responseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory ?? throw new LogicException(
            'Dispatcher::handle() needs a ResponseFactoryInterface; pass one to the constructor.',
        );
    }

    /**
     * @param list<RouteMatch> $matches
     *
     * @return list<string>
     */
    private function allowed(array $matches, ServerRequestInterface $request): array
    {
        $allowed = [];
        foreach ($matches as $match) {
            $methods = Methods::of($match->route);
            if ($methods === null || !$this->guardsAccept($match->route, $request, $match->params)) {
                continue;
            }

            foreach ($methods as $method) {
                $allowed[$method] = true;
            }
        }

        if ($allowed['GET'] ?? false) {
            $allowed['HEAD'] = true;
        }

        return array_keys($allowed);
    }

    /**
     * The request's path, normalized to start with exactly one "/" for the router.
     */
    private static function normalizedPath(ServerRequestInterface $request): string
    {
        return '/' . ltrim($request->getUri()->getPath(), '/');
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
            $instance = $this->resolve($guard);
            if (!$instance->accepts($config, $request, $params)) {
                return false;
            }
        }

        return true;
    }
}
