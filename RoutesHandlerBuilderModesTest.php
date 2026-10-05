<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use ArrayObject;
use Closure;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Http\RoutesHandlerBuilder;
use SilenZ\Segmatch\Tests\Http\Fixtures\ArrayContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\ArrayRouteCache;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\NumericRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\StatusMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\TagMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\UserController;
use UnexpectedValueException;

use function preg_quote;

/**
 * The same routes declared eagerly with `routes()` and lazily with `lazyRoutes()` answer the same,
 * and what sets the two apart: when the declaration runs, and what it may contain.
 */
final class RoutesHandlerBuilderModesTest extends TestCase
{
    /**
     * Declares $define on a builder whose container knows the "log", "api" and "auth" middleware, a
     * {@see NumericRouteFilter} for "id" under its class name and one for "postId" under
     * "numeric.postId", and "not-a-filter", so the routes can name everything, as lazy routes must.
     *
     * @param callable(Routes): void $define
     */
    private static function declared(string $mode, callable $define): RoutesHandlerBuilder
    {
        $builder = new RoutesHandlerBuilder(new EchoContainer([
            'log' => new TagMiddleware('log'),
            'api' => new TagMiddleware('api'),
            'auth' => new TagMiddleware('auth'),
            NumericRouteFilter::class => new NumericRouteFilter('id'),
            'numeric.postId' => new NumericRouteFilter('postId'),
            'not-a-filter' => new TagMiddleware('oops'),
        ]));

        if ($mode === 'lazy') {
            $builder->lazyRoutes($define);
        } else {
            $define($builder->routes());
        }

        return $builder;
    }

    private static function api(Routes $r): void
    {
        $r->middleware('log');
        $api = $r->group('/api')->middleware('api');
        $api->get('/users/{id}', 'show')->middleware('auth')->filter(NumericRouteFilter::class);
        $api->put('/users/{id}', 'update');
        $r->get('/ping', 'ping');
    }

    private static function respond(RoutesHandlerBuilder $builder, string $method, string $path): ResponseInterface
    {
        $request = new ServerRequest($method, $path);

        return $builder->handler($request)->handle($request);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'eager' => ['eager'];
        yield 'lazy' => ['lazy'];
    }

    #[DataProvider('modes')]
    public function testAMatchedRouteRunsInsideTheRootGroupAndRouteMiddleware(string $mode): void
    {
        $response = self::respond(self::declared($mode, self::api(...)), 'GET', '/api/users/42');

        static::assertSame('show', $response->getHeaderLine('X-Handler'));
        static::assertSame('auth,api,log', $response->getHeaderLine('X-Trail'));
    }

    #[DataProvider('modes')]
    public function testFiltersNamedByClassAreResolvedFromTheContainer(string $mode): void
    {
        // The filter rejects "john", leaving only PUT for the path.
        $response = self::respond(self::declared($mode, self::api(...)), 'GET', '/api/users/john');

        static::assertSame(405, $response->getStatusCode());
        static::assertSame('PUT', $response->getHeaderLine('Allow'));
    }

    #[DataProvider('modes')]
    public function testAFilterMayBeAContainerIdentifierToConfigureItPerRoute(string $mode): void
    {
        $builder = self::declared($mode, static function (Routes $r): void {
            $r->get('/users/{id}', 'user')->filter(NumericRouteFilter::class);
            $r->get('/posts/{postId}', 'post')->filter('numeric.postId');
        });

        static::assertSame('post', self::respond($builder, 'GET', '/posts/7')->getHeaderLine('X-Handler'));
        static::assertSame(404, self::respond($builder, 'GET', '/posts/new')->getStatusCode());
    }

    #[DataProvider('modes')]
    public function testAFilterIdentifierResolvingToSomethingElseFailsClearly(string $mode): void
    {
        $builder = self::declared($mode, static fn(Routes $r) => $r->get('/x', 'x')->filter('not-a-filter'));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches(
            '/^Filter "not-a-filter" resolved to ' . preg_quote(TagMiddleware::class, delimiter: '/') . ', which/',
        );

        self::respond($builder, 'GET', '/x');
    }

    #[DataProvider('modes')]
    public function testAHandlerMayBeAClassAndMethodPair(string $mode): void
    {
        $builder = self::declared($mode, static function (Routes $r): void {
            $r->middleware('log');
            $r->get('/users/{id}', [UserController::class, 'show'])->middleware('auth');
        });

        $response = self::respond($builder, 'GET', '/users/42');

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('42', $response->getHeaderLine('X-User'));
        static::assertSame('auth,log', $response->getHeaderLine('X-Trail'));
    }

    #[DataProvider('modes')]
    public function testAHandlerMethodMustReturnAResponse(string $mode): void
    {
        $builder = self::declared($mode, static fn(Routes $r) => $r->get('/x', [UserController::class, 'broken']));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/::broken\(\) returned string instead of a /');

        self::respond($builder, 'GET', '/x');
    }

    public function testAHandlerPairMayHoldAnInstanceWhenDeclaredEagerly(): void
    {
        $builder = self::declared('eager', static fn(Routes $r) => $r->get('/users/{id}', [
            new UserController(),
            'show',
        ]));

        static::assertSame('7', self::respond($builder, 'GET', '/users/7')->getHeaderLine('X-User'));
    }

    public function testAHandlerPairsTargetIsOnlyResolvedOnceTheRequestReachesIt(): void
    {
        // The container knows nothing but the middleware, so resolving the target would throw.
        $builder = new RoutesHandlerBuilder(new ArrayContainer(['deny' => new StatusMiddleware(401)]));
        $builder->lazyRoutes(static fn(Routes $r) => $r->get('/x', ['unknown.controller', 'show'])->middleware('deny'));

        static::assertSame(401, self::respond($builder, 'GET', '/x')->getStatusCode());
    }

    #[DataProvider('modes')]
    public function testRootMiddlewareWrapsEveryOutcome(string $mode): void
    {
        $builder = self::declared($mode, self::api(...));

        $notFound = self::respond($builder, 'GET', '/nope');
        $notAllowed = self::respond($builder, 'POST', '/ping');
        $options = self::respond($builder, 'OPTIONS', '/ping');
        $head = self::respond($builder, 'HEAD', '/ping');

        static::assertSame([404, 'log'], [$notFound->getStatusCode(), $notFound->getHeaderLine('X-Trail')]);
        static::assertSame([405, 'log'], [$notAllowed->getStatusCode(), $notAllowed->getHeaderLine('X-Trail')]);
        static::assertSame([200, 'log'], [$options->getStatusCode(), $options->getHeaderLine('X-Trail')]);
        static::assertSame([200, 'log', ''], [
            $head->getStatusCode(),
            $head->getHeaderLine('X-Trail'),
            (string) $head->getBody(),
        ]);
    }

    #[DataProvider('modes')]
    public function testRootMiddlewareWrapsANotFoundAnsweredFromTheCache(string $mode): void
    {
        $cache = new ArrayRouteCache();
        self::declared($mode, self::api(...))
            ->router($cache, 'routes')
            ->match('/');

        // A warm builder whose declaration would say otherwise: the cached root middleware applies.
        $warm = self::declared($mode, static fn(Routes $r) => $r->middleware('auth'));
        $warm->router($cache, 'routes');

        $response = self::respond($warm, 'GET', '/nope');

        static::assertSame(404, $response->getStatusCode());
        static::assertSame('log', $response->getHeaderLine('X-Trail'));
    }

    public function testLazyRoutesAreOnlyDeclaredWhenTheCacheHasNoEntry(): void
    {
        $cache = new ArrayRouteCache();
        $calls = new ArrayObject();
        $define = static function (Routes $r) use ($calls): void {
            $calls->append(true);
            self::api($r);
        };

        $cold = self::declared('lazy', $define);
        $cold->router($cache, 'routes');
        static::assertCount(0, $calls, 'Declaring waits until the routes are needed.');

        static::assertSame('ping', self::respond($cold, 'GET', '/ping')->getHeaderLine('X-Handler'));
        static::assertCount(1, $calls);

        $warm = self::declared('lazy', $define);
        $warm->router($cache, 'routes');
        static::assertSame('ping', self::respond($warm, 'GET', '/ping')->getHeaderLine('X-Handler'));
        static::assertSame('log', self::respond($warm, 'GET', '/nope')->getHeaderLine('X-Trail'));
        static::assertCount(1, $calls, 'A request answered from the cache declares nothing.');
    }

    public function testEagerRoutesMayUseInstancesEvenForRootMiddlewareOnAWarmCache(): void
    {
        $cache = new ArrayRouteCache();
        $define = static function (Routes $r): void {
            $r->middleware(new TagMiddleware('instance'));
            $r->get('/x', new PlainHandler());
        };

        self::declared('eager', $define)->router($cache, 'routes')->match('/x');

        $warm = self::declared('eager', $define);
        $warm->router($cache, 'routes');

        static::assertSame('instance', self::respond($warm, 'GET', '/x')->getHeaderLine('X-Trail'));
        static::assertSame('instance', self::respond($warm, 'GET', '/nope')->getHeaderLine('X-Trail'));
    }

    /**
     * @return iterable<string, array{Closure(Routes): void, string}>
     */
    public static function instancesInLazyRoutes(): iterable
    {
        yield 'handler' => [
            static fn(Routes $r) => $r->get('/x', new PlainHandler()),
            'Route "/x" handler must be a class name or a container identifier, not ' . PlainHandler::class,
        ];
        yield 'route middleware' => [
            static fn(Routes $r) => $r->get('/x', 'x')->middleware(new TagMiddleware('t')),
            'Route "/x" middleware must be a class name or a container identifier',
        ];
        yield 'root middleware' => [
            static fn(Routes $r) => $r->middleware(static fn() => null),
            'Routes without a prefix middleware must be a class name or a container identifier, not Closure',
        ];
        yield 'filter' => [
            static fn(Routes $r) => $r->get('/x', 'x')->filter(new NumericRouteFilter('id')),
            'Route "/x" filter must be a class name or a container identifier',
        ];
    }

    /**
     * @param Closure(Routes): void $define
     */
    #[DataProvider('instancesInLazyRoutes')]
    public function testLazyRoutesRejectInstances(Closure $define, string $message): void
    {
        $builder = self::declared('lazy', $define);

        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '/');

        self::respond($builder, 'GET', '/x');
    }

    /**
     * @return iterable<string, array{Closure(RoutesHandlerBuilder): mixed, string}>
     */
    public static function misuse(): iterable
    {
        $nothing = static function (Routes $_r): void {};

        yield 'routes() after lazyRoutes()' => [
            static function (RoutesHandlerBuilder $b) use ($nothing): Routes {
                $b->lazyRoutes($nothing);

                return $b->routes();
            },
            'Routes are declared lazily with lazyRoutes(), so routes() cannot be used too.',
        ];
        yield 'lazyRoutes() after routes()' => [
            static function (RoutesHandlerBuilder $b) use ($nothing): null {
                $b->routes();
                $b->lazyRoutes($nothing);

                return null;
            },
            'Routes can only be declared once',
        ];
        yield 'lazyRoutes() twice' => [
            static function (RoutesHandlerBuilder $b) use ($nothing): null {
                $b->lazyRoutes($nothing);
                $b->lazyRoutes($nothing);

                return null;
            },
            'Routes can only be declared once',
        ];
        yield 'lazyRoutes() after the router is built' => [
            static function (RoutesHandlerBuilder $b) use ($nothing): null {
                $b->routes();
                $b->router();
                $b->lazyRoutes($nothing);

                return null;
            },
            'Routes can only be declared once',
        ];
        yield 'router() with nothing declared' => [
            static fn(RoutesHandlerBuilder $b) => $b->router(),
            'No routes are declared: call routes() or lazyRoutes() first.',
        ];
        yield 'handler() with nothing declared' => [
            static fn(RoutesHandlerBuilder $b) => $b->handler(new ServerRequest('GET', '/')),
            'No routes are declared: call routes() or lazyRoutes() first.',
        ];
    }

    /**
     * @param Closure(RoutesHandlerBuilder): mixed $misuse
     */
    #[DataProvider('misuse')]
    public function testMisuseThrows(Closure $misuse, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '/');

        $misuse(new RoutesHandlerBuilder(new EchoContainer()));
    }

    public function testRoutesKeepsWorkingAfterTheRouterIsBuiltOnceItWasCalledBefore(): void
    {
        $builder = new RoutesHandlerBuilder(new EchoContainer());
        $routes = $builder->routes();
        $builder->router();

        static::assertSame($routes, $builder->routes());
    }
}
