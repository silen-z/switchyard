<?php

/**
 * The same tiny app as examples/eager-routes, on top of Switchyard's LazyRoutes instead. Runnable with PHP's
 * built-in server:
 *
 *     php -S localhost:8000 index.php
 *
 * Then try the same requests as the eager example:
 *
 *     curl http://localhost:8000/
 *     curl http://localhost:8000/hello/world
 *     curl http://localhost:8000/api/users/42
 *     curl -H "Authorization: Bearer x" http://localhost:8000/api/users/42
 *     curl -i http://localhost:8000/nope
 *     curl -i -X POST http://localhost:8000/hello/world
 *
 * LazyRoutes only declares the tree below when the route cache has no entry for it — a request
 * answered from the cache runs none of the ->get()/->group() calls below at all. The price: every
 * handler and middleware must be a class name or container identifier, never a real instance or
 * closure (there is none to hold onto past the request that would have declared it), so the handlers
 * below are small classes resolved through the container, unlike the eager example's closures.
 *
 * Delete var/cache (or bump the cache key below) and request / again to see X-Routes-Declared flip
 * from "no" back to "yes" for one request.
 */

declare(strict_types=1);

namespace SilenZ\Switchyard\Examples\LazyRoutes;

require __DIR__ . '/../../vendor/autoload.php';

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use SilenZ\Beeline\Cache\FileCache;
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\Attribute\Found;
use SilenZ\Switchyard\Handler;
use SilenZ\Switchyard\Handler\AllowedMethodsHandler;
use SilenZ\Switchyard\Handler\NotFoundHandler;
use SilenZ\Switchyard\LazyRoutes;
use SilenZ\Switchyard\Middleware\ErrorMiddleware;
use SilenZ\Switchyard\Middleware\HeadMiddleware;

/** A trivial PSR-11 container backed by a fixed map, standing in for the application's own. */
final class ArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(
        private readonly array $services,
    ) {}

    public function get(string $id): object
    {
        if (!array_key_exists($id, $this->services)) {
            throw new RuntimeException(sprintf('Service "%s" not found.', $id));
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}

/** Named by class below — the only way a LazyRoutes middleware entry can point at it. */
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

final class HomeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Psr17Factory $psr17,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return json($this->psr17, [
            'message' => 'Lazy routes: Switchyard\LazyRoutes, declared only on a route cache miss.',
            'try' => ['/hello/{name}', '/api/users/{id}'],
        ]);
    }
}

final class HelloHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Psr17Factory $psr17,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return json($this->psr17, ['hello' => found($request)->params['name']]);
    }
}

final class ShowUserHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Psr17Factory $psr17,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return json($this->psr17, ['user' => found($request)->params['id']]);
    }
}

function found(ServerRequestInterface $request): Found
{
    return Found::fromRequest($request) ?? throw new LogicException('Only a matched route carries a Found attribute.');
}

/**
 * @param array<string, mixed> $data
 */
function json(Psr17Factory $psr17, array $data): ResponseInterface
{
    return $psr17
        ->createResponse(200)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($psr17->createStream(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"));
}

$psr17 = new Psr17Factory();

$declared = false;
$table = LazyRoutes::table(static function (LazyRoutes $routes) use (&$declared): void {
    $declared = true;

    $routes->get('/', HomeHandler::class)->name('home');
    $routes->get('/hello/{name}', HelloHandler::class)->name('hello');
    $routes
        ->group('/api')
        ->middleware(AuthMiddleware::class)
        ->get('/users/{id}', ShowUserHandler::class)
        ->name('users.show');
}, 'examples-lazy-routes-v1');

$container = new ArrayContainer([
    ResponseFactoryInterface::class => $psr17,
    NotFoundHandler::class => new NotFoundHandler($psr17),
    AllowedMethodsHandler::class => new AllowedMethodsHandler($psr17),
    HeadMiddleware::class => new HeadMiddleware($psr17),
    ErrorMiddleware::class => new ErrorMiddleware($psr17, $psr17),
    AuthMiddleware::class => new AuthMiddleware($psr17),
    HomeHandler::class => new HomeHandler($psr17),
    HelloHandler::class => new HelloHandler($psr17),
    ShowUserHandler::class => new ShowUserHandler($psr17),
]);

$router = new Router($table, cache: new FileCache(__DIR__ . '/var/cache'));
$handler = new Handler($container, $router);

// Psr17Factory::createServerRequest() builds no headers at all from $_SERVER — the built-in server
// also hides Authorization from $_SERVER itself unless getallheaders() is used, so that's where the
// AuthMiddleware above actually reads it from.
$headers = getallheaders();
$request = new ServerRequest(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
    $headers === false ? [] : $headers,
    null,
    '1.1',
    $_SERVER,
);

$response = $handler->handle($request)->withHeader('X-Routes-Declared', $declared ? 'yes' : 'no');

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", replace: false);
    }
}
echo (string) $response->getBody();
