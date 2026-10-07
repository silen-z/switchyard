<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests;

use ArrayObject;
use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SilenZ\Beeline\Cache\RouteCache;
use SilenZ\Beeline\Exception\InvalidRouteException;
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\Handler;
use SilenZ\Switchyard\LazyRoutes;
use SilenZ\Switchyard\Middleware\ErrorMiddleware;
use SilenZ\Switchyard\Routes;
use SilenZ\Switchyard\Tests\Fixtures\ArrayContainer;
use SilenZ\Switchyard\Tests\Fixtures\ArrayRouteCache;
use SilenZ\Switchyard\Tests\Fixtures\CustomErrorMiddleware;
use SilenZ\Switchyard\Tests\Fixtures\EchoContainer;
use SilenZ\Switchyard\Tests\Fixtures\NumericRouteFilter;
use SilenZ\Switchyard\Tests\Fixtures\PlainHandler;
use SilenZ\Switchyard\Tests\Fixtures\StatusHandler;
use SilenZ\Switchyard\Tests\Fixtures\StatusMiddleware;
use SilenZ\Switchyard\Tests\Fixtures\TagMiddleware;
use SilenZ\Switchyard\Tests\Fixtures\ThrowingHandler;
use SilenZ\Switchyard\Tests\Fixtures\UserController;
use UnexpectedValueException;

use function preg_quote;

/**
 * The same routes declared eagerly with `Routes` and lazily with `LazyRoutes` answer the same, and
 * what sets the two apart: when the declaration runs, and what it may contain.
 */
final class HandlerModesTest extends TestCase
{
    /**
     * Builds a `Handler` from `$define`, declared eagerly on a `Routes` or lazily on a
     * `LazyRoutes` depending on `$mode`. Only for a `$define` genuinely polymorphic over both — the
     * parameterized `#[DataProvider('modes')]` tests. A test that only runs in one mode should call
     * {@see declaredEagerly()} or {@see declaredLazily()} directly instead, so its closure can be typed
     * to the one it actually needs rather than the union this method has to accept.
     *
     * @param callable(Routes|LazyRoutes): mixed $define
     */
    private static function declared(
        string $mode,
        callable $define,
        ?RouteCache $cache = null,
        ?string $cacheKey = null,
    ): Handler {
        if ($mode === 'lazy') {
            return self::declaredLazily($define, $cache, $cacheKey);
        }

        return self::declaredEagerly($define, $cache, $cacheKey);
    }

    /**
     * Builds a `Handler` from `$define` declared eagerly on a `Routes`, against a container
     * that knows the "log", "api" and "auth" middleware and a {@see NumericRouteFilter} for "id" under
     * its class name.
     *
     * @param callable(Routes): mixed $define
     */
    private static function declaredEagerly(
        callable $define,
        ?RouteCache $cache = null,
        ?string $cacheKey = null,
    ): Handler {
        $routes = new Routes();
        $define($routes);

        return new Handler(self::container(), new Router($routes->table($cacheKey), $cache));
    }

    /**
     * Builds a `Handler` from `$define` declared lazily on a `LazyRoutes`, against a container
     * that also knows "numeric.postId" and "not-a-filter", so lazy routes can name everything, as they
     * must.
     *
     * @param callable(LazyRoutes): mixed $define
     */
    private static function declaredLazily(
        callable $define,
        ?RouteCache $cache = null,
        ?string $cacheKey = null,
    ): Handler {
        return new Handler(self::container(), new Router(LazyRoutes::table($define, $cacheKey), $cache));
    }

    private static function container(): EchoContainer
    {
        return new EchoContainer([
            'log' => new TagMiddleware('log'),
            'api' => new TagMiddleware('api'),
            'auth' => new TagMiddleware('auth'),
            'status-410' => new StatusHandler(410),
            'status-599' => new CustomErrorMiddleware(599),
            NumericRouteFilter::class => new NumericRouteFilter('id'),
            'numeric.postId' => new NumericRouteFilter('postId'),
            'not-a-filter' => new TagMiddleware('oops'),
        ]);
    }

    private static function api(Routes|LazyRoutes $r): void
    {
        $r->middleware('log');
        $api = $r->group('/api')->middleware('api');
        $api->get('/users/{id}', 'show')->middleware('auth')->filter(NumericRouteFilter::class);
        $api->put('/users/{id}', 'update');
        $r->get('/ping', 'ping');
    }

    private static function respond(Handler $builder, string $method, string $path): ResponseInterface
    {
        $request = new ServerRequest($method, $path);

        return $builder->build($request)->handle($request);
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
    public function testRedirectWorksTheSameEagerOrLazy(string $mode): void
    {
        $builder = self::declared($mode, static fn(Routes|LazyRoutes $r) => $r->redirect('/old', '/new', 301));

        $response = self::respond($builder, 'GET', '/old');

        static::assertSame(301, $response->getStatusCode());
        static::assertSame('/new', $response->getHeaderLine('Location'));
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
        $builder = self::declared($mode, static function (Routes|LazyRoutes $r): void {
            $r->get('/users/{id}', 'user')->filter(NumericRouteFilter::class);
            $r->get('/posts/{postId}', 'post')->filter('numeric.postId');
        });

        static::assertSame('post', self::respond($builder, 'GET', '/posts/7')->getHeaderLine('X-Handler'));
        static::assertSame(404, self::respond($builder, 'GET', '/posts/new')->getStatusCode());
    }

    #[DataProvider('modes')]
    public function testAFilterIdentifierResolvingToSomethingElseFailsClearly(string $mode): void
    {
        $builder = self::declared($mode, static fn(Routes|LazyRoutes $r) => $r->get('/x', 'x')->filter('not-a-filter'));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches(
            '/^Filter "not-a-filter" resolved to ' . preg_quote(TagMiddleware::class, delimiter: '/') . ', which/',
        );

        self::respond($builder, 'GET', '/x');
    }

    /**
     * `Routes` never gives a `[target, 'method']` handler any special treatment: it's kept as plain
     * data like any other handler, and reaches Relay exactly as declared. This works only because
     * Relay itself treats a `[$instance, 'method']` array as an ordinary callable — not because this
     * library resolves or validates the pair in any way. `LazyRoutes` can't do this at all: an
     * instance can't survive to a later request answered from the cache, and a `[class name, 'method']`
     * pair isn't callable on its own either (there's no instance to call the method on) — see
     * {@see testAHandlerTargetIsOnlyResolvedOnceTheRequestReachesIt()} for the lazy equivalent.
     */
    public function testAHandlerPairWithAnInstanceWorksThroughMiddlewareWhenDeclaredEagerly(): void
    {
        $builder = self::declaredEagerly(static function (Routes $r): void {
            $r->middleware('log');
            $r->get('/users/{id}', [new UserController(), 'show'])->middleware('auth');
        });

        $response = self::respond($builder, 'GET', '/users/42');

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('42', $response->getHeaderLine('X-User'));
        static::assertSame('auth,log', $response->getHeaderLine('X-Trail'));
    }

    /**
     * Relay's own return-type check, not anything of this library's doing: {@see
     * \Relay\Runner::handle()} is declared to return a `ResponseInterface`, so a handler returning
     * anything else is a `TypeError` — just another `Throwable` for the default `ErrorMiddleware` to
     * turn into a 500.
     */
    public function testAHandlerMethodMustReturnAResponse(): void
    {
        $builder = self::declaredEagerly(static fn(Routes $r) => $r->get('/x', [new UserController(), 'broken']));

        $response = self::respond($builder, 'GET', '/x');

        static::assertSame(500, $response->getStatusCode());
        static::assertSame('Server error', (string) $response->getBody());
    }

    public function testAHandlerTargetIsOnlyResolvedOnceTheRequestReachesIt(): void
    {
        // The container knows nothing but the middleware and Handler's own fallbacks, so
        // resolving the handler would throw.
        $psr17 = new Psr17Factory();
        $container = new ArrayContainer([
            'deny' => new StatusMiddleware(401),
            ErrorMiddleware::class => new ErrorMiddleware($psr17, $psr17),
        ]);
        $table = LazyRoutes::table(static fn(LazyRoutes $r) => $r->get('/x', 'unknown.controller')->middleware('deny'));
        $builder = new Handler($container, new Router($table));

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
    public function testNotFoundMayBeReplaced(string $mode): void
    {
        $builder = self::declared($mode, static function (Routes|LazyRoutes $r): void {
            self::api($r);
            $r->notFound('status-410');
        });

        static::assertSame(410, self::respond($builder, 'GET', '/nope')->getStatusCode());

        // The 405 and OPTIONS answers, and matched routes, are unaffected.
        static::assertSame(405, self::respond($builder, 'POST', '/ping')->getStatusCode());
        static::assertSame(200, self::respond($builder, 'OPTIONS', '/ping')->getStatusCode());
        static::assertSame('ping', self::respond($builder, 'GET', '/ping')->getHeaderLine('X-Handler'));
    }

    #[DataProvider('modes')]
    public function testACustomErrorMiddlewareCatchesBeforeTheDefault(string $mode): void
    {
        $builder = self::declared($mode, static function (Routes|LazyRoutes $r): void {
            // Declared first, so it wraps every other root middleware while itself sitting inside
            // the default ErrorMiddleware — the innermost catch wins, so this one answers first.
            $r->middleware('status-599');
            self::api($r);
            $r->get('/boom', ThrowingHandler::class);
        });

        static::assertSame(599, self::respond($builder, 'GET', '/boom')->getStatusCode());
    }

    public function testLazyRootMiddlewareWrapsANotFoundAnsweredFromTheCache(): void
    {
        $cache = new ArrayRouteCache();
        self::respond(self::declaredLazily(self::api(...), $cache, 'routes'), 'GET', '/');

        // A warm builder whose declaration would say otherwise: LazyRoutes only declares on a cache
        // miss, so this middleware never actually runs — the cached root middleware applies instead.
        $warm = self::declaredLazily(static fn(LazyRoutes $r) => $r->middleware('auth'), $cache, 'routes');

        $response = self::respond($warm, 'GET', '/nope');

        static::assertSame(404, $response->getStatusCode());
        static::assertSame('log', $response->getHeaderLine('X-Trail'));
    }

    /**
     * Unlike {@see testLazyRootMiddlewareWrapsANotFoundAnsweredFromTheCache()}, eager routes always
     * re-declare, warm cache or not — so the warm builder here uses the same declaration as the cold
     * one (changing it on a warm request, with the same cache key, is already a cache-key misuse, not
     * something this registry design can paper over). What this actually checks is that a second,
     * independently declared {@see Routes} tree still resolves the right metadata through its own
     * freshly built {@see \SilenZ\Beeline\MetadataRegistry} against a route tree compiled earlier.
     */
    public function testEagerRootMiddlewareIsReResolvedFromALiveRegistryOnAWarmCache(): void
    {
        $cache = new ArrayRouteCache();
        self::respond(self::declaredEagerly(self::api(...), $cache, 'routes'), 'GET', '/');

        $warm = self::declaredEagerly(self::api(...), $cache, 'routes');

        $response = self::respond($warm, 'GET', '/nope');

        static::assertSame(404, $response->getStatusCode());
        static::assertSame('log', $response->getHeaderLine('X-Trail'));
    }

    public function testLazyRoutesAreOnlyDeclaredWhenTheCacheHasNoEntry(): void
    {
        $cache = new ArrayRouteCache();
        $calls = new ArrayObject();
        $define = static function (LazyRoutes $r) use ($calls): void {
            $calls->append(true);
            self::api($r);
        };

        $cold = self::declaredLazily($define, $cache, 'routes');
        static::assertCount(0, $calls, 'Declaring waits until the routes are needed.');

        static::assertSame('ping', self::respond($cold, 'GET', '/ping')->getHeaderLine('X-Handler'));
        static::assertCount(1, $calls);

        $warm = self::declaredLazily($define, $cache, 'routes');
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

        self::respond(self::declaredEagerly($define, $cache, 'routes'), 'GET', '/x');

        $warm = self::declaredEagerly($define, $cache, 'routes');

        static::assertSame('instance', self::respond($warm, 'GET', '/x')->getHeaderLine('X-Trail'));
        static::assertSame('instance', self::respond($warm, 'GET', '/nope')->getHeaderLine('X-Trail'));
    }

    /**
     * @return iterable<string, array{Closure(LazyRoutes): void, string}>
     */
    public static function instancesInLazyRoutes(): iterable
    {
        // Deliberately not a class name or container identifier: LazyRoutes must reject it at runtime.
        // @mago-expect analysis:invalid-argument
        yield 'handler' => [
            static fn(LazyRoutes $r) => $r->get('/x', new PlainHandler()),
            'Route "/x" handler must be a class name or a container identifier, not ' . PlainHandler::class,
        ];
        // LazyRoute's handler is a plain string, full stop — an array is rejected the same as any
        // other non-string, with no special case for a `[target, 'method']` shape.
        // @mago-expect analysis:invalid-argument
        yield 'handler pair target' => [
            static fn(LazyRoutes $r) => $r->get('/x', [new PlainHandler(), 'handle']),
            'Route "/x" handler must be a class name or a container identifier, not array',
        ];
        // Deliberately not a class name or container identifier: LazyRoute must reject it at runtime.
        // @mago-expect analysis:invalid-argument
        yield 'route middleware' => [
            static fn(LazyRoutes $r) => $r->get('/x', 'x')->middleware(new TagMiddleware('t')),
            'Route "/x" middleware must be a class name or a container identifier',
        ];
        // Deliberately not a class name or container identifier: LazyRoutes must reject it at runtime.
        // @mago-expect analysis:possibly-invalid-argument
        yield 'root middleware' => [
            static fn(LazyRoutes $r) => $r->middleware(static fn() => null),
            'Routes without a prefix middleware must be a class name or a container identifier, not Closure',
        ];
        // Deliberately not a class name or container identifier: LazyRoute must reject it at runtime.
        // @mago-expect analysis:invalid-argument
        yield 'filter' => [
            static fn(LazyRoutes $r) => $r->get('/x', 'x')->filter(new NumericRouteFilter('id')),
            'Route "/x" filter must be a class name or a container identifier',
        ];
    }

    /**
     * @param Closure(LazyRoutes): void $define
     */
    #[DataProvider('instancesInLazyRoutes')]
    public function testLazyRoutesRejectInstances(Closure $define, string $message): void
    {
        $builder = self::declaredLazily($define);

        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '/');

        self::respond($builder, 'GET', '/x');
    }

    public function testLazyRoutesNotFoundMayOnlyBeSetOnTheRoot(): void
    {
        $builder = self::declaredLazily(static fn(LazyRoutes $r) => $r->group('/api')->notFound('x'));

        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches(
            '/^'
            . preg_quote('Only the root Routes may set the not-found handler, not a nested group.', delimiter: '/')
            . '/',
        );

        self::respond($builder, 'GET', '/x');
    }
}
