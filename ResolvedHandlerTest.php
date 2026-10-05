<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Http\Found;
use SilenZ\Segmatch\Http\HandlerResolver;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\ArrayContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\NumericRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\RouteInfoMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\ShowHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\StatusHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\TagMiddleware;

final class ResolvedHandlerTest extends TestCase
{
    private static function responseFactory(): ResponseFactoryInterface
    {
        return new Psr17Factory();
    }

    /**
     * @param callable(Routes): void $define
     */
    private static function router(callable $define): Router
    {
        $routes = new Routes();
        $define($routes);

        return new Router($routes->table());
    }

    private static function resolver(?RequestHandlerInterface $notFoundHandler = null): HandlerResolver
    {
        $routes = new Routes();
        $routes->get('/ping', PlainHandler::class);
        $routes->get('/users', PlainHandler::class);
        $routes->post('/users', PlainHandler::class);
        $routes->get('/users/{id}', PlainHandler::class)->filter(new NumericRouteFilter('id'));
        $routes->get('/users/{slug}', PlainHandler::class);
        $routes->map(['PUT', 'PATCH'], '/users/{id}', PlainHandler::class)->filter(new NumericRouteFilter('id'));
        $routes->get('/beta', PlainHandler::class)->filter(new FeatureRouteFilter('beta'));
        $routes->any('/webhooks/{provider}', PlainHandler::class);
        $routes->get('/cors', PlainHandler::class);
        $routes->map(['OPTIONS'], '/cors', PlainHandler::class);

        return new HandlerResolver(
            new Router($routes->table()),
            responseFactory: self::responseFactory(),
            notFoundHandler: $notFoundHandler,
            registry: $routes->registry(),
        );
    }

    private static function respond(HandlerResolver $resolver, ServerRequestInterface $request): ResponseInterface
    {
        return $resolver->resolve($request)->handle($request);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requests(): iterable
    {
        yield 'found' => ['GET', '/ping'];
        yield 'not found' => ['GET', '/nope'];
        yield 'method not allowed' => ['DELETE', '/ping'];
    }

    #[DataProvider('requests')]
    public function testResolveReturnsAPsr15RequestHandler(string $method, string $path): void
    {
        static::assertInstanceOf(
            RequestHandlerInterface::class,
            self::resolver()->resolve(new ServerRequest($method, $path)),
        );
    }

    public function testRunsTheRoutesMiddlewareThenItsHandler(): void
    {
        $responseFactory = self::responseFactory();
        $router = self::router(static function (Routes $r): void {
            $r->get('/users/{id}', 'show')->middleware(['first', 'second']);
        });
        $container = new ArrayContainer([
            'show' => new ShowHandler($responseFactory),
            'first' => new TagMiddleware('first'),
            'second' => new TagMiddleware('second'),
        ]);
        $resolver = new HandlerResolver($router, $responseFactory, $container);

        $response = self::respond($resolver, new ServerRequest('GET', '/users/42'));

        static::assertSame(200, $response->getStatusCode());
        // The "id" route parameter reached the handler through the Found attribute.
        static::assertSame('42', $response->getHeaderLine('X-Id'));
        // Both middleware ran around the handler, outermost declared first.
        static::assertSame('second,first', $response->getHeaderLine('X-Trail'));
    }

    public function testRouteParametersAreOnlyInTheFoundAttribute(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(204);
            }
        };
        $resolver = new HandlerResolver(
            self::router(static function (Routes $r): void {
                $r->get('/users/{id}', 'show');
            }),
            self::responseFactory(),
            new ArrayContainer(['show' => $handler]),
        );

        self::respond($resolver, new ServerRequest('GET', '/users/42'));

        // @mago-expect analysis:mixed-assignment
        $found = $handler->request?->getAttribute(Found::class);
        static::assertInstanceOf(Found::class, $found);
        static::assertSame(['id' => '42'], $found->params);
        static::assertNull($handler->request?->getAttribute('id'));
    }

    public function testRouteMiddlewareSeesTheWholeMatch(): void
    {
        $resolver = new HandlerResolver(self::router(static function (Routes $r): void {
            $r
                ->group('/api')
                ->tag('json')
                ->get('/users/{id}', PlainHandler::class)
                ->name('users.show')
                ->tag('public')
                ->middleware(RouteInfoMiddleware::class);
        }), self::responseFactory());

        $response = self::respond($resolver, new ServerRequest('GET', '/api/users/42'));

        static::assertSame('users.show', $response->getHeaderLine('X-Route-Name'));
        static::assertSame('json,public', $response->getHeaderLine('X-Route-Tags'));
    }

    public function testHandlerWithoutDependenciesDoesNotNeedAContainer(): void
    {
        $response = self::respond(self::resolver(), new ServerRequest('GET', '/ping'));

        static::assertSame(204, $response->getStatusCode());
    }

    public function testNotFoundBuildsA404Response(): void
    {
        $response = self::respond(self::resolver(), new ServerRequest('GET', '/nope'));

        static::assertSame(404, $response->getStatusCode());
    }

    public function testMethodNotAllowedBuildsA405ResponseWithTheAllowHeader(): void
    {
        $response = self::respond(self::resolver(), new ServerRequest('POST', '/ping'));

        static::assertSame(405, $response->getStatusCode());
        static::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    /**
     * @return iterable<string, array{ServerRequest, string}>
     */
    public static function optionsRequests(): iterable
    {
        yield 'GET implies HEAD' => [new ServerRequest('OPTIONS', '/ping'), 'GET, HEAD'];
        yield 'several routes' => [new ServerRequest('OPTIONS', '/users'), 'GET, POST, HEAD'];
        yield 'filter accepts' => [new ServerRequest('OPTIONS', '/users/7'), 'GET, PUT, PATCH, HEAD'];
        // NumericFilter rejects "john", so only the slug route counts.
        yield 'filter rejects some' => [new ServerRequest('OPTIONS', '/users/john'), 'GET, HEAD'];
        yield 'feature on' => [
            new ServerRequest('OPTIONS', '/beta')->withAttribute('features', ['beta' => true]),
            'GET, HEAD',
        ];
    }

    #[DataProvider('optionsRequests')]
    public function testOptionsAnswers200WithTheAllowHeader(ServerRequest $request, string $allow): void
    {
        $response = self::respond(self::resolver(), $request);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame($allow, $response->getHeaderLine('Allow'));
    }

    public function testOptionsForRoutesRejectedByTheirOwnFiltersIsNotFound(): void
    {
        static::assertSame(
            404,
            self::respond(self::resolver(), new ServerRequest('OPTIONS', '/beta'))->getStatusCode(),
        );
        static::assertSame(
            404,
            self::respond(self::resolver(), new ServerRequest('OPTIONS', '/nope'))->getStatusCode(),
        );
    }

    public function testOptionsReachesRoutesThatAcceptIt(): void
    {
        // any() routes accept every method, OPTIONS included; so does an explicit OPTIONS route.
        foreach (['/webhooks/github', '/cors'] as $path) {
            $response = self::respond(self::resolver(), new ServerRequest('OPTIONS', $path));

            static::assertSame(204, $response->getStatusCode(), $path);
            static::assertFalse($response->hasHeader('Allow'), $path);
        }
    }

    public function testApplicationMiddlewareWrapsEveryOutcome(): void
    {
        $resolver = new HandlerResolver(
            self::router(static function (Routes $r): void {
                $r->get('/users/{id}', PlainHandler::class)->name('users.show')->middleware('route');
            }),
            self::responseFactory(),
            new ArrayContainer([
                PlainHandler::class => new PlainHandler(),
                'route' => new TagMiddleware('route'),
                'outer' => new TagMiddleware('outer'),
            ]),
        );
        // By identifier, resolved from the container, and as an instance; the first added is outermost.
        $resolver->addMiddleware('outer');
        $resolver->addMiddleware(new TagMiddleware('inner'), new RouteInfoMiddleware());

        // A matched route: outside the route's own middleware, and seeing its Found.
        $found = self::respond($resolver, new ServerRequest('GET', '/users/42'));
        static::assertSame(204, $found->getStatusCode());
        static::assertSame('route,inner,outer', $found->getHeaderLine('X-Trail'));
        static::assertSame('users.show', $found->getHeaderLine('X-Route-Name'));

        // 405 and OPTIONS: seeing the allowed methods.
        foreach (['POST' => 405, 'OPTIONS' => 200] as $method => $status) {
            $response = self::respond($resolver, new ServerRequest($method, '/users/42'));
            static::assertSame($status, $response->getStatusCode(), $method);
            static::assertSame('inner,outer', $response->getHeaderLine('X-Trail'), $method);
            static::assertSame('GET,HEAD', $response->getHeaderLine('X-Route-Allowed'), $method);
        }

        // 404: no routing result to see, but it still runs.
        $notFound = self::respond($resolver, new ServerRequest('GET', '/nope'));
        static::assertSame(404, $notFound->getStatusCode());
        static::assertSame('inner,outer', $notFound->getHeaderLine('X-Trail'));
        static::assertFalse($notFound->hasHeader('X-Route-Name'));
        static::assertFalse($notFound->hasHeader('X-Route-Allowed'));
    }

    public function testOwnNotFoundHandlerReplacesTheDefault(): void
    {
        $resolver = self::resolver(notFoundHandler: new StatusHandler(410));

        static::assertSame(410, self::respond($resolver, new ServerRequest('GET', '/nope'))->getStatusCode());

        // The 405 and OPTIONS answers stay the defaults, and matched routes are unaffected.
        $methodNotAllowed = self::respond($resolver, new ServerRequest('POST', '/ping'));
        static::assertSame(405, $methodNotAllowed->getStatusCode());
        static::assertSame('GET, HEAD', $methodNotAllowed->getHeaderLine('Allow'));

        $options = self::respond($resolver, new ServerRequest('OPTIONS', '/ping'));
        static::assertSame(200, $options->getStatusCode());
        static::assertSame('GET, HEAD', $options->getHeaderLine('Allow'));

        static::assertSame(204, self::respond($resolver, new ServerRequest('GET', '/ping'))->getStatusCode());
    }
}
