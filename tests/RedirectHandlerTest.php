<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\HandlerBuilder;
use SilenZ\Switchyard\RedirectHandler;
use SilenZ\Switchyard\Routes;
use SilenZ\Switchyard\Tests\Fixtures\EchoContainer;

/**
 * {@see RedirectHandler} on its own — {@see TrailingSlashTest} covers {@see HandlerBuilder::build()}
 * using one for a trailing-slash redirect, but it's a plain {@see \Psr\Http\Server\RequestHandlerInterface}
 * a route may just as well use directly, e.g. for a moved path — or, more conveniently,
 * {@see Routes::redirect()} declares one for you.
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

    public function testRoutesRedirectIsSugarForARedirectHandlerBuiltPerRequest(): void
    {
        $routes = new Routes();
        $routes->redirect('/old', '/new');
        $routes->redirect('/old-temp', '/new-temp', 301);

        $builder = new HandlerBuilder(new EchoContainer(), new Router($routes->table()));

        $response = $builder->build(new ServerRequest('GET', '/old'))->handle(new ServerRequest('GET', '/old'));
        static::assertSame(308, $response->getStatusCode());
        static::assertSame('/new', $response->getHeaderLine('Location'));

        $temp = $builder->build(new ServerRequest('GET', '/old-temp'))->handle(new ServerRequest('GET', '/old-temp'));
        static::assertSame(301, $temp->getStatusCode());
        static::assertSame('/new-temp', $temp->getHeaderLine('Location'));
    }

    public function testRoutesRedirectRespondsToHeadWithTheBodyStripped(): void
    {
        $routes = new Routes();
        $routes->redirect('/old', '/new');

        $builder = new HandlerBuilder(new EchoContainer(), new Router($routes->table()));
        $request = new ServerRequest('HEAD', '/old');
        $response = $builder->build($request)->handle($request);

        static::assertSame(308, $response->getStatusCode());
        static::assertSame('/new', $response->getHeaderLine('Location'));
        static::assertSame('', (string) $response->getBody());
    }

    public function testRoutesRedirectOnlyAnswersGet(): void
    {
        $routes = new Routes();
        $routes->redirect('/old', '/new');

        $builder = new HandlerBuilder(new EchoContainer(), new Router($routes->table()));
        $request = new ServerRequest('DELETE', '/old');
        $response = $builder->build($request)->handle($request);

        static::assertSame(405, $response->getStatusCode());
        static::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }
}
