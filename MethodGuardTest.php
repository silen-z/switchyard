<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\MethodGuard;
use SilenZ\Segmatch\Http\RouteCollector;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;

final class MethodGuardTest extends TestCase
{
    private static function router(): Router
    {
        return new Router(Routes::define(static function (RouteCollector $r): void {
            $r->get('/users', 'list');
            $r->post('/users', 'create');
            $r->post('/users/new', 'create-form');
            $r->get('/users/{id}', 'show');
            $r->map(['PUT', 'PATCH'], '/users/{id}', 'update');
            $r->any('/webhooks/{provider}', 'webhook');
        }));
    }

    private static function handler(RouteMatch|NoMatch $result): mixed
    {
        if (!$result instanceof RouteMatch) {
            return null;
        }

        /** @var array{handler: mixed} $route */
        $route = $result->route;

        return $route['handler'];
    }

    public function testSelectsTheRouteForTheMethod(): void
    {
        $router = self::router();

        static::assertSame('list', self::handler($router->match('/users', MethodGuard::for('GET'))));
        static::assertSame('create', self::handler($router->match('/users', MethodGuard::for('POST'))));
        static::assertSame('update', self::handler($router->match('/users/7', MethodGuard::for('PATCH'))));
    }

    public function testMethodIsCaseInsensitive(): void
    {
        static::assertSame('create', self::handler(self::router()->match('/users', MethodGuard::for('post'))));
    }

    public function testFallsThroughToARouteThatAcceptsTheMethod(): void
    {
        $result = self::router()->match('/users/new', MethodGuard::for('GET'));

        static::assertSame('show', self::handler($result));
        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame(['id' => 'new'], $result->params);
    }

    public function testAnyAcceptsEveryMethod(): void
    {
        static::assertSame(
            'webhook',
            self::handler(self::router()->match('/webhooks/github', MethodGuard::for('PURGE'))),
        );
    }

    public function testRejectedRoutesProduceTheAllowList(): void
    {
        $result = self::router()->match('/users', MethodGuard::for('DELETE'));

        static::assertInstanceOf(NoMatch::class, $result);
        static::assertSame(['GET', 'POST'], MethodGuard::allowed($result));
    }

    public function testAllowListMergesAllRejectedBranches(): void
    {
        $result = self::router()->match('/users/new', MethodGuard::for('DELETE'));

        static::assertInstanceOf(NoMatch::class, $result);
        static::assertSame(['POST', 'GET', 'PUT', 'PATCH'], MethodGuard::allowed($result));
    }

    public function testUnknownPathHasNoAllowedMethods(): void
    {
        $result = self::router()->match('/nope', MethodGuard::for('GET'));

        static::assertInstanceOf(NoMatch::class, $result);
        static::assertSame([], MethodGuard::allowed($result));
    }

    public function testRoutesWithoutMethodMetadataAreRejected(): void
    {
        $router = new Router(static fn($routes) => $routes->add('/raw', 'not http metadata'));

        static::assertInstanceOf(NoMatch::class, $router->match('/raw', MethodGuard::for('GET')));
    }
}
