<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\Dispatcher;
use SilenZ\Segmatch\Http\Found;
use SilenZ\Segmatch\Http\MethodNotAllowed;
use SilenZ\Segmatch\Http\NotFound;
use SilenZ\Segmatch\Http\Request;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureGuard;

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
            self::dispatcher()->dispatch('get', '/api/users/42'),
        );
    }

    public function testAnyRoutesHaveNoMethods(): void
    {
        static::assertEquals(
            new Found(handler: 'webhook', params: ['provider' => 'github']),
            self::dispatcher()->dispatch('PURGE', '/webhooks/github'),
        );
    }

    public function testHeadMatchesGetRoutes(): void
    {
        $result = self::dispatcher()->dispatch('HEAD', '/api/users/42');

        static::assertInstanceOf(Found::class, $result);
        static::assertSame('show', $result->handler);
    }

    public function testHeadRoutesWinOverGetRoutes(): void
    {
        $result = self::dispatcher()->dispatch('HEAD', '/ping');

        static::assertInstanceOf(Found::class, $result);
        static::assertSame('ping-head', $result->handler);
    }

    public function testMethodNotAllowedListsTheAllowedMethods(): void
    {
        static::assertEquals(
            new MethodNotAllowed(['GET', 'HEAD', 'PUT']),
            self::dispatcher()->dispatch('DELETE', '/api/users/42'),
        );
        static::assertEquals(new MethodNotAllowed(['POST']), self::dispatcher()->dispatch('HEAD', '/login'));
    }

    public function testNotFound(): void
    {
        static::assertEquals(new NotFound(), self::dispatcher()->dispatch('GET', '/nope'));
    }

    public function testRoutesRejectedByTheirOwnGuardsAreNotFound(): void
    {
        $dispatcher = self::dispatcher();

        static::assertEquals(new NotFound(), $dispatcher->dispatch('GET', '/api/beta'));
        static::assertInstanceOf(Found::class, $dispatcher->dispatch(new Request('GET', ['features' => [
            'beta' => true,
        ]]), '/api/beta'));
    }

    public function testAllowedMethods(): void
    {
        $dispatcher = self::dispatcher();

        static::assertSame(['GET', 'HEAD', 'PUT'], $dispatcher->allowedMethods('/api/users/42'));
        static::assertSame(['GET', 'HEAD'], $dispatcher->allowedMethods('/ping'));
        static::assertSame([], $dispatcher->allowedMethods('/nope'));
        static::assertSame([], $dispatcher->allowedMethods('/webhooks/github'));
        static::assertSame([], $dispatcher->allowedMethods('/api/beta'));
        static::assertSame(['GET', 'HEAD'], $dispatcher->allowedMethods('/api/beta', ['features' => ['beta' => true]]));
    }
}
