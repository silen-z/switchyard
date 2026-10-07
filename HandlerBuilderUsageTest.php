<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\AllowedMethodsHandler;
use SilenZ\Segmatch\Http\ErrorMiddleware;
use SilenZ\Segmatch\Http\HandlerBuilder;
use SilenZ\Segmatch\Http\HeadMiddleware;
use SilenZ\Segmatch\Http\LazyRoutes;
use SilenZ\Segmatch\Http\NotFoundHandler;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\ArrayContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\NumericRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\StatusMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\TagMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\UserController;

/**
 * The same small users API, wired up once with {@see Routes} and once with {@see LazyRoutes}, each
 * straight-line in its own test with no shared `builder()`/`respond()` helper in between: this is what
 * the call sites actually look like.
 *
 * Eager routes may hand over real instances directly, so the container below only has to resolve
 * {@see HandlerBuilder}'s own fallbacks. Lazy routes can't: every middleware, filter and handler
 * target has to be a class name or container identifier instead, so the same container also has to
 * know "log", "auth", {@see UserController} and the pre-configured {@see NumericRouteFilter} — and,
 * since nothing it declares is ever wrapped, there's no registry setup to do for it either:
 * `$router->table()->registry()` is just the empty default every `RouteTable` carries.
 */
final class HandlerBuilderUsageTest extends TestCase
{
    public function testEagerRoutesAnswerRequestsEndToEnd(): void
    {
        $psr17 = new Psr17Factory();
        $container = new ArrayContainer([
            NotFoundHandler::class => new NotFoundHandler($psr17),
            AllowedMethodsHandler::class => new AllowedMethodsHandler($psr17),
            HeadMiddleware::class => new HeadMiddleware($psr17),
            ErrorMiddleware::class => new ErrorMiddleware($psr17, $psr17),
        ]);

        $routes = new Routes();
        $routes->middleware(new TagMiddleware('log'));
        $routes->get('/users', new PlainHandler());
        $routes->get('/users/{id}', [new UserController(), 'show'])->filter(new NumericRouteFilter('id'));

        $admin = $routes->group('/admin')->middleware(new StatusMiddleware(401));
        $admin->get('/stats', new PlainHandler());

        $builder = new HandlerBuilder($container, new Router($routes->table()));

        $request = new ServerRequest('GET', '/users/42');
        $response = $builder->build($request)->handle($request);
        static::assertSame(200, $response->getStatusCode());
        static::assertSame('42', $response->getHeaderLine('X-User'));
        static::assertSame('log', $response->getHeaderLine('X-Trail'));

        // The filter rejects a non-numeric id, so no route is left for the path: not found, same as an
        // unknown path, with the root middleware still wrapping the response.
        $request = new ServerRequest('GET', '/users/new');
        $response = $builder->build($request)->handle($request);
        static::assertSame(404, $response->getStatusCode());
        static::assertSame('log', $response->getHeaderLine('X-Trail'));

        // The admin group's own middleware denies the request before its handler ever runs.
        $request = new ServerRequest('GET', '/admin/stats');
        $response = $builder->build($request)->handle($request);
        static::assertSame(401, $response->getStatusCode());
        static::assertSame('log', $response->getHeaderLine('X-Trail'));

        // An existing path, wrong method: 405 with the allowed methods, root middleware still runs.
        $request = new ServerRequest('DELETE', '/users');
        $response = $builder->build($request)->handle($request);
        static::assertSame(405, $response->getStatusCode());
        static::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        static::assertSame('log', $response->getHeaderLine('X-Trail'));
    }

    public function testLazyRoutesAnswerRequestsEndToEnd(): void
    {
        $psr17 = new Psr17Factory();
        $container = new ArrayContainer([
            NotFoundHandler::class => new NotFoundHandler($psr17),
            AllowedMethodsHandler::class => new AllowedMethodsHandler($psr17),
            HeadMiddleware::class => new HeadMiddleware($psr17),
            ErrorMiddleware::class => new ErrorMiddleware($psr17, $psr17),
            'log' => new TagMiddleware('log'),
            'auth' => new StatusMiddleware(401),
            PlainHandler::class => new PlainHandler(),
            UserController::class => new UserController(),
            // One filter per configuration, named by its class since there's only one "id" filter here.
            NumericRouteFilter::class => new NumericRouteFilter('id'),
        ]);

        $table = LazyRoutes::table(static function (LazyRoutes $routes): void {
            $routes->middleware('log');
            $routes->get('/users', PlainHandler::class);
            $routes->get('/users/{id}', [UserController::class, 'show'])->filter(NumericRouteFilter::class);

            $admin = $routes->group('/admin')->middleware('auth');
            $admin->get('/stats', PlainHandler::class);
        });
        $builder = new HandlerBuilder($container, new Router($table));

        $request = new ServerRequest('GET', '/users/42');
        $response = $builder->build($request)->handle($request);
        static::assertSame(200, $response->getStatusCode());
        static::assertSame('42', $response->getHeaderLine('X-User'));
        static::assertSame('log', $response->getHeaderLine('X-Trail'));

        $request = new ServerRequest('GET', '/users/new');
        $response = $builder->build($request)->handle($request);
        static::assertSame(404, $response->getStatusCode());
        static::assertSame('log', $response->getHeaderLine('X-Trail'));

        $request = new ServerRequest('GET', '/admin/stats');
        $response = $builder->build($request)->handle($request);
        static::assertSame(401, $response->getStatusCode());
        static::assertSame('log', $response->getHeaderLine('X-Trail'));

        $request = new ServerRequest('DELETE', '/users');
        $response = $builder->build($request)->handle($request);
        static::assertSame(405, $response->getStatusCode());
        static::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        static::assertSame('log', $response->getHeaderLine('X-Trail'));
    }
}
