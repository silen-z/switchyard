<?php

/**
 * A tiny app on top of Switchyard's Routes, runnable with PHP's built-in server:
 *
 *     php -S localhost:8000 index.php
 *
 * Then try:
 *
 *     curl http://localhost:8000/
 *     curl http://localhost:8000/hello/world
 *     curl http://localhost:8000/api/users/42
 *     curl -H "Authorization: Bearer x" http://localhost:8000/api/users/42
 *     curl -i http://localhost:8000/nope
 *     curl -i -X POST http://localhost:8000/hello/world
 *
 * Routes declares the whole tree on every request — there's no way around that: a handler or
 * middleware given as a real instance (as below) only exists for as long as the request that declared
 * it, so it can never be loaded back from a route cache. That's what Routes' own
 * MetadataRegistry is for (see switchyard/README.md's "HTTP routes" section) — only *that* is ever skipped on a
 * cache hit, the compiled path tree below it. So a FileCache still pays off here: what's expensive is
 * the segment-tree compilation, not re-running a handful of ->get()/->group() calls, and the
 * X-Routes-Declared header below is "yes" on every single request to make that explicit. Compare
 * examples/lazy-routes, where that header flips to "no" once the cache is warm.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Beeline\Cache\FileCache;
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\AllowedMethodsHandler;
use SilenZ\Switchyard\ErrorMiddleware;
use SilenZ\Switchyard\Found;
use SilenZ\Switchyard\HandlerBuilder;
use SilenZ\Switchyard\HeadMiddleware;
use SilenZ\Switchyard\NotFoundHandler;
use SilenZ\Switchyard\Routes;

/** A trivial PSR-11 container backed by a fixed map, standing in for the application's own. */
final class ArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(
        private readonly array $services,
    ) {}

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new RuntimeException(sprintf('Service "%s" not found.', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

/** A real instance, not a class name — only possible because Routes keeps it out of the cache. */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine('Authorization') === '') {
            return $this->responseFactory->createResponse(401)->withHeader('WWW-Authenticate', 'Bearer');
        }

        return $handler->handle($request);
    }
}

final class JsonHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Psr17Factory $psr17,
        private readonly Closure $body,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $data = ($this->body)($request->getAttribute(Found::class));

        return $this->psr17
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->psr17->createStream(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"));
    }
}

$psr17 = new Psr17Factory();

$routes = new Routes();
$routes->get('/', new JsonHandler($psr17, static fn(): array => [
    'message' => 'Eager routes: Switchyard\Routes, declared fresh on every request.',
    'try' => ['/hello/{name}', '/api/users/{id}'],
]))->name('home');

$routes->get('/hello/{name}', new JsonHandler($psr17, static fn(Found $found): array => [
    'hello' => $found->params['name'],
]))->name('hello');

$api = $routes->group('/api')->middleware(new AuthMiddleware($psr17));
$api->get('/users/{id}', new JsonHandler($psr17, static fn(Found $found): array => [
    'user' => $found->params['id'],
]))->name('users.show');

$container = new ArrayContainer([
    ResponseFactoryInterface::class => $psr17,
    NotFoundHandler::class => new NotFoundHandler($psr17),
    AllowedMethodsHandler::class => new AllowedMethodsHandler($psr17),
    HeadMiddleware::class => new HeadMiddleware($psr17),
    ErrorMiddleware::class => new ErrorMiddleware($psr17, $psr17),
]);

$router = new Router($routes->table('examples-eager-routes-v1'), cache: new FileCache(__DIR__ . '/var/cache'));
$builder = new HandlerBuilder($container, $router);

// Psr17Factory::createServerRequest() builds no headers at all from $_SERVER — the built-in server
// also hides Authorization from $_SERVER itself unless getallheaders() is used, so that's where the
// AuthMiddleware above actually reads it from.
$request = new ServerRequest(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
    getallheaders(),
    null,
    '1.1',
    $_SERVER,
);

$response = $builder->build($request)->handle($request)->withHeader('X-Routes-Declared', 'yes');

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", replace: false);
    }
}
echo $response->getBody();
