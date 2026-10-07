<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Http\ErrorMiddleware;
use SilenZ\Segmatch\Http\Found;
use SilenZ\Segmatch\Http\HandlerBuilder;
use SilenZ\Segmatch\Http\NotFoundHandler;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\ArrayContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\CustomErrorMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\NumericRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\RouteInfoMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\ShowHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\StatusHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\TagMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\ThrowingHandler;

final class ResolvedHandlerTest extends TestCase
{
    /**
     * @param callable(Routes): void $define
     */
    private static function builder(callable $define, ContainerInterface $container): HandlerBuilder
    {
        $routes = new Routes();
        $define($routes);

        return new HandlerBuilder($container, new Router($routes->table()));
    }

    private static function apiBuilder(): HandlerBuilder
    {
        return self::builder(static function (Routes $routes): void {
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
        }, new EchoContainer());
    }

    private static function respond(
        HandlerBuilder $builder,
        ServerRequestInterface $request,
        RequestHandlerInterface|string $notFoundHandler = NotFoundHandler::class,
    ): ResponseInterface {
        return $builder->build($request, $notFoundHandler)->handle($request);
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
    public function testHandlerReturnsAPsr15RequestHandler(string $method, string $path): void
    {
        static::assertInstanceOf(
            RequestHandlerInterface::class,
            self::apiBuilder()->build(new ServerRequest($method, $path)),
        );
    }

    public function testRunsTheRoutesMiddlewareThenItsHandler(): void
    {
        $psr17 = new Psr17Factory();
        $container = new ArrayContainer([
            'show' => new ShowHandler($psr17),
            'first' => new TagMiddleware('first'),
            'second' => new TagMiddleware('second'),
            ErrorMiddleware::class => new ErrorMiddleware($psr17, $psr17),
        ]);
        $builder = self::builder(static function (Routes $r): void {
            $r->get('/users/{id}', 'show')->middleware(['first', 'second']);
        }, $container);

        $response = self::respond($builder, new ServerRequest('GET', '/users/42'));

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
        $psr17 = new Psr17Factory();
        $builder = self::builder(
            static function (Routes $r): void {
                $r->get('/users/{id}', 'show');
            },
            new ArrayContainer(['show' => $handler, ErrorMiddleware::class => new ErrorMiddleware($psr17, $psr17)]),
        );

        self::respond($builder, new ServerRequest('GET', '/users/42'));

        // @mago-expect analysis:mixed-assignment
        $found = $handler->request?->getAttribute(Found::class);
        static::assertInstanceOf(Found::class, $found);
        static::assertSame(['id' => '42'], $found->params);
        static::assertNull($handler->request?->getAttribute('id'));
    }

    public function testRouteMiddlewareSeesTheWholeMatch(): void
    {
        $builder = self::builder(static function (Routes $r): void {
            $r
                ->group('/api')
                ->tag('json')
                ->get('/users/{id}', PlainHandler::class)
                ->name('users.show')
                ->tag('public')
                ->middleware(RouteInfoMiddleware::class);
        }, new EchoContainer());

        $response = self::respond($builder, new ServerRequest('GET', '/api/users/42'));

        static::assertSame('users.show', $response->getHeaderLine('X-Route-Name'));
        static::assertSame('json,public', $response->getHeaderLine('X-Route-Tags'));
    }

    public function testHandlerWithoutDependenciesDoesNotNeedAContainer(): void
    {
        $response = self::respond(self::apiBuilder(), new ServerRequest('GET', '/ping'));

        static::assertSame(204, $response->getStatusCode());
    }

    public function testNotFoundBuildsA404Response(): void
    {
        $response = self::respond(self::apiBuilder(), new ServerRequest('GET', '/nope'));

        static::assertSame(404, $response->getStatusCode());
    }

    public function testMethodNotAllowedBuildsA405ResponseWithTheAllowHeader(): void
    {
        $response = self::respond(self::apiBuilder(), new ServerRequest('POST', '/ping'));

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
        // NumericRouteFilter rejects "john", so only the slug route counts.
        yield 'filter rejects some' => [new ServerRequest('OPTIONS', '/users/john'), 'GET, HEAD'];
        yield 'feature on' => [
            new ServerRequest('OPTIONS', '/beta')->withAttribute('features', ['beta' => true]),
            'GET, HEAD',
        ];
    }

    #[DataProvider('optionsRequests')]
    public function testOptionsAnswers200WithTheAllowHeader(ServerRequest $request, string $allow): void
    {
        $response = self::respond(self::apiBuilder(), $request);

        static::assertSame(200, $response->getStatusCode());
        static::assertSame($allow, $response->getHeaderLine('Allow'));
    }

    public function testOptionsForRoutesRejectedByTheirOwnFiltersIsNotFound(): void
    {
        static::assertSame(
            404,
            self::respond(self::apiBuilder(), new ServerRequest('OPTIONS', '/beta'))->getStatusCode(),
        );
        static::assertSame(
            404,
            self::respond(self::apiBuilder(), new ServerRequest('OPTIONS', '/nope'))->getStatusCode(),
        );
    }

    public function testOptionsReachesRoutesThatAcceptIt(): void
    {
        // any() routes accept every method, OPTIONS included; so does an explicit OPTIONS route.
        foreach (['/webhooks/github', '/cors'] as $path) {
            $response = self::respond(self::apiBuilder(), new ServerRequest('OPTIONS', $path));

            static::assertSame(204, $response->getStatusCode(), $path);
            static::assertFalse($response->hasHeader('Allow'), $path);
        }
    }

    public function testOwnNotFoundHandlerReplacesTheDefault(): void
    {
        $builder = self::apiBuilder();
        $notFoundHandler = new StatusHandler(410);

        static::assertSame(
            410,
            self::respond($builder, new ServerRequest('GET', '/nope'), $notFoundHandler)->getStatusCode(),
        );

        // The 405 and OPTIONS answers stay the defaults, and matched routes are unaffected.
        $methodNotAllowed = self::respond($builder, new ServerRequest('POST', '/ping'));
        static::assertSame(405, $methodNotAllowed->getStatusCode());
        static::assertSame('GET, HEAD', $methodNotAllowed->getHeaderLine('Allow'));

        $options = self::respond($builder, new ServerRequest('OPTIONS', '/ping'));
        static::assertSame(200, $options->getStatusCode());
        static::assertSame('GET, HEAD', $options->getHeaderLine('Allow'));

        static::assertSame(204, self::respond($builder, new ServerRequest('GET', '/ping'))->getStatusCode());
    }

    public function testDefaultErrorMiddlewareCatchesAThrowingHandler(): void
    {
        $builder = self::builder(static fn(Routes $r) => $r->get('/boom', ThrowingHandler::class), new EchoContainer());

        $response = self::respond($builder, new ServerRequest('GET', '/boom'));

        static::assertSame(500, $response->getStatusCode());
        static::assertSame('Server error', (string) $response->getBody());
    }

    public function testOwnErrorMiddlewareReplacesTheDefault(): void
    {
        $builder = self::builder(static fn(Routes $r) => $r->get('/boom', ThrowingHandler::class), new EchoContainer());
        $request = new ServerRequest('GET', '/boom');

        $response = $builder->build($request, errorMiddleware: new CustomErrorMiddleware(599))->handle($request);

        static::assertSame(599, $response->getStatusCode());
    }
}
