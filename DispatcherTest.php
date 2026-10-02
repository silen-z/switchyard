<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\Dispatcher;
use SilenZ\Segmatch\Http\Found;
use SilenZ\Segmatch\Http\MethodNotAllowed;
use SilenZ\Segmatch\Http\NotFound;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\ArrayContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\ConfigurableGuard;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureGuard;
use SilenZ\Segmatch\Tests\Http\Fixtures\NumericGuard;

final class DispatcherTest extends TestCase
{
    private static function dispatcher(): Dispatcher
    {
        return new Dispatcher(new Router(Routes::define(static function (Routes $r): void {
            $r
                ->group('/api')
                ->middleware('api')
                ->tag('json')
                ->define(static function (Routes $r): void {
                    $r->get('/users/{id}', 'show')->name('users.show')->middleware('auth')->tag('public');
                    $r->put('/users/{id}', 'update');
                    $r->get('/beta', 'beta')->guard(FeatureGuard::class, 'beta');
                });
            $r->get('/ping', 'ping');
            $r->map(['HEAD'], '/ping', 'ping-head');
            $r->post('/login', 'login');
            $r->any('/webhooks/{provider}', 'webhook');
        })));
    }

    public function testFoundCarriesTheRoute(): void
    {
        static::assertEquals(
            new Found(
                handler: 'show',
                params: ['id' => '42'],
                middleware: ['api', 'auth'],
                name: 'users.show',
                methods: ['GET'],
                tags: ['json', 'public'],
            ),
            self::dispatcher()->dispatch(new ServerRequest('get', '/api/users/42')),
        );
    }

    public function testAnyRoutesHaveNoMethods(): void
    {
        static::assertEquals(
            new Found(handler: 'webhook', params: ['provider' => 'github']),
            self::dispatcher()->dispatch(new ServerRequest('PURGE', '/webhooks/github')),
        );
    }

    public function testHeadMatchesGetRoutes(): void
    {
        $result = self::dispatcher()->dispatch(new ServerRequest('HEAD', '/api/users/42'));

        static::assertInstanceOf(Found::class, $result);
        static::assertSame('show', $result->handler);
    }

    public function testHeadRoutesWinOverGetRoutes(): void
    {
        $result = self::dispatcher()->dispatch(new ServerRequest('HEAD', '/ping'));

        static::assertInstanceOf(Found::class, $result);
        static::assertSame('ping-head', $result->handler);
    }

    public function testMethodNotAllowedListsTheAllowedMethods(): void
    {
        static::assertEquals(
            new MethodNotAllowed(['GET', 'HEAD', 'PUT']),
            self::dispatcher()->dispatch(new ServerRequest('DELETE', '/api/users/42')),
        );
        static::assertEquals(
            new MethodNotAllowed(['POST']),
            self::dispatcher()->dispatch(new ServerRequest('HEAD', '/login')),
        );
    }

    public function testNotFound(): void
    {
        static::assertEquals(new NotFound(), self::dispatcher()->dispatch(new ServerRequest('GET', '/nope')));
    }

    public function testRoutesRejectedByTheirOwnGuardsAreNotFound(): void
    {
        $dispatcher = self::dispatcher();

        static::assertEquals(new NotFound(), $dispatcher->dispatch(new ServerRequest('GET', '/api/beta')));
        static::assertInstanceOf(Found::class, $dispatcher->dispatch(
            (new ServerRequest('GET', '/api/beta'))->withAttribute('features', ['beta' => true]),
        ));
    }

    public function testAllowedMethods(): void
    {
        $dispatcher = self::dispatcher();

        static::assertSame(['GET', 'HEAD', 'PUT'], $dispatcher->allowedMethods(new ServerRequest('OPTIONS', '/api/users/42')));
        static::assertSame(['GET', 'HEAD'], $dispatcher->allowedMethods(new ServerRequest('OPTIONS', '/ping')));
        static::assertSame([], $dispatcher->allowedMethods(new ServerRequest('OPTIONS', '/nope')));
        static::assertSame([], $dispatcher->allowedMethods(new ServerRequest('OPTIONS', '/webhooks/github')));
        static::assertSame([], $dispatcher->allowedMethods(new ServerRequest('OPTIONS', '/api/beta')));
        static::assertSame(
            ['GET', 'HEAD'],
            $dispatcher->allowedMethods(
                (new ServerRequest('OPTIONS', '/api/beta'))->withAttribute('features', ['beta' => true]),
            ),
        );
    }

    public function testRoutesWithoutMethodsOrGuardsAlwaysApply(): void
    {
        $dispatcher = new Dispatcher(
            new Router(static fn(): array => [new RouteDefinition('/raw', ['handler' => 'raw'])]),
        );

        $result = $dispatcher->dispatch(new ServerRequest('DELETE', '/raw'));

        static::assertInstanceOf(Found::class, $result);
        static::assertSame('raw', $result->handler);
    }

    private static function routingDispatcher(): Dispatcher
    {
        return new Dispatcher(new Router(Routes::define(static function (Routes $r): void {
            $r->get('/users', 'list');
            $r->post('/users', 'create');
            $r->post('/users/new', 'create-form');
            $r->get('/users/{id}', 'show')->guard(NumericGuard::class, 'id');
            $r->get('/users/{slug}', 'by-slug');
            $r->map(['PUT', 'PATCH'], '/users/{id}', 'update')->guard(NumericGuard::class, 'id');
            $r->get('/{path+}', 'frontend');
        })));
    }

    public function testFallsThroughToARouteThatAcceptsTheMethod(): void
    {
        $result = self::routingDispatcher()->dispatch(new ServerRequest('GET', '/users/new'));

        static::assertInstanceOf(Found::class, $result);
        static::assertSame('by-slug', $result->handler);
        static::assertSame(['slug' => 'new'], $result->params);
    }

    public function testPatternsChooseBetweenRoutesOfTheSameShape(): void
    {
        $dispatcher = self::routingDispatcher();

        $show = $dispatcher->dispatch(new ServerRequest('GET', '/users/42'));
        static::assertInstanceOf(Found::class, $show);
        static::assertSame('show', $show->handler);

        $bySlug = $dispatcher->dispatch(new ServerRequest('GET', '/users/john'));
        static::assertInstanceOf(Found::class, $bySlug);
        static::assertSame('by-slug', $bySlug->handler);
    }

    public function testAllowedMethodsComeFromRoutesRejectedOnlyForTheirMethod(): void
    {
        $dispatcher = self::routingDispatcher();

        static::assertSame(['GET', 'HEAD', 'POST'], $dispatcher->allowedMethods(new ServerRequest('DELETE', '/users')));

        // "/users/7": show and update match the pattern, by-slug matches too, the catch-all is GET.
        static::assertSame(
            ['GET', 'HEAD', 'PUT', 'PATCH'],
            $dispatcher->allowedMethods(new ServerRequest('DELETE', '/users/7')),
        );

        // "/users/john": show and update's numeric guard fails, so only by-slug and the catch-all count.
        static::assertSame(['GET', 'HEAD'], $dispatcher->allowedMethods(new ServerRequest('DELETE', '/users/john')));
    }

    public function testRouteRejectedForAnotherReasonDoesNotCountAsAllowed(): void
    {
        $dispatcher = new Dispatcher(new Router(Routes::define(static function (Routes $r): void {
            $r->get('/beta', 'beta')->guard(FeatureGuard::class, 'beta');
        })));

        static::assertSame([], $dispatcher->allowedMethods(new ServerRequest('POST', '/beta')));
    }

    public function testGuardsAreResolvedFromTheContainer(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/locked', 'locked')->guard(ConfigurableGuard::class);
        }));

        $allowing = new ArrayContainer([ConfigurableGuard::class => new ConfigurableGuard(accepts: true)]);
        $result = new Dispatcher($router, $allowing)->dispatch(new ServerRequest('GET', '/locked'));
        static::assertInstanceOf(Found::class, $result);
        static::assertSame('locked', $result->handler);

        $blocking = new ArrayContainer([ConfigurableGuard::class => new ConfigurableGuard(accepts: false)]);
        $result = new Dispatcher($router, $blocking)->dispatch(new ServerRequest('GET', '/locked'));
        static::assertInstanceOf(NotFound::class, $result);
    }
}
