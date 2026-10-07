<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\HandlerBuilder;
use SilenZ\Segmatch\Http\RedirectHandler;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoContainer;

/**
 * {@see RedirectHandler} on its own — {@see TrailingSlashTest} covers {@see HandlerBuilder::build()}
 * using one for a trailing-slash redirect, but it's a plain {@see \Psr\Http\Server\RequestHandlerInterface}
 * a route may just as well use directly, e.g. for a moved path.
 */
final class RedirectHandlerTest extends TestCase
{
    public function testDefaultsTo308(): void
    {
        $psr17 = new Psr17Factory();
        $response = new RedirectHandler($psr17, '/new')->handle(new ServerRequest('GET', '/old'));

        static::assertSame(308, $response->getStatusCode());
        static::assertSame('/new', $response->getHeaderLine('Location'));
    }

    public function testAcceptsAnotherStatus(): void
    {
        $psr17 = new Psr17Factory();
        $response = new RedirectHandler($psr17, '/new', 301)->handle(new ServerRequest('GET', '/old'));

        static::assertSame(301, $response->getStatusCode());
    }

    public function testMayBeUsedDirectlyAsARouteHandler(): void
    {
        $psr17 = new Psr17Factory();
        $routes = new Routes();
        $routes->get('/old', new RedirectHandler($psr17, '/new'));

        $builder = new HandlerBuilder(new EchoContainer(), new Router($routes->table()));
        $request = new ServerRequest('GET', '/old');
        $response = $builder->build($request)->handle($request);

        static::assertSame(308, $response->getStatusCode());
        static::assertSame('/new', $response->getHeaderLine('Location'));
    }
}
