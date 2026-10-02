<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Http\Dispatcher;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\ArrayContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\ShowHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\TagMiddleware;

final class HandleTest extends TestCase
{
    public function testDispatcherIsAPsr15RequestHandler(): void
    {
        $dispatcher = new Dispatcher(
            new Router(Routes::define(static function (Routes $r): void {
                $r->get('/ping', PlainHandler::class);
            })),
            responseFactory: self::responseFactory(),
        );

        static::assertInstanceOf(RequestHandlerInterface::class, $dispatcher);
        static::assertSame(204, $dispatcher->handle(new ServerRequest('GET', '/ping'))->getStatusCode());
    }

    private static function responseFactory(): ResponseFactoryInterface
    {
        return new Psr17Factory();
    }

    public function testRunsTheRoutesMiddlewareThenItsHandler(): void
    {
        $responseFactory = self::responseFactory();
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/users/{id}', 'show')->middleware(['first', 'second']);
        }));
        $container = new ArrayContainer([
            'show' => new ShowHandler($responseFactory),
            'first' => new TagMiddleware('first'),
            'second' => new TagMiddleware('second'),
        ]);
        $dispatcher = new Dispatcher($router, $container, $responseFactory);

        $response = $dispatcher->handle(new ServerRequest('GET', '/users/42'));

        static::assertSame(200, $response->getStatusCode());
        // The "id" route parameter reached the handler as a request attribute.
        static::assertSame('42', $response->getHeaderLine('X-Id'));
        // Both middleware ran around the handler, outermost declared first.
        static::assertSame('second,first', $response->getHeaderLine('X-Trail'));
    }

    public function testHandlerWithoutDependenciesDoesNotNeedAContainer(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/ping', PlainHandler::class);
        }));
        $dispatcher = new Dispatcher($router, container: null, responseFactory: self::responseFactory());

        $response = $dispatcher->handle(new ServerRequest('GET', '/ping'));

        static::assertSame(204, $response->getStatusCode());
    }

    public function testNotFoundBuildsA404Response(): void
    {
        $dispatcher = new Dispatcher(
            new Router(Routes::define(static function (Routes $r): void {
                $r->get('/ping', PlainHandler::class);
            })),
            responseFactory: self::responseFactory(),
        );

        $response = $dispatcher->handle(new ServerRequest('GET', '/nope'));

        static::assertSame(404, $response->getStatusCode());
    }

    public function testMethodNotAllowedBuildsA405ResponseWithTheAllowHeader(): void
    {
        $dispatcher = new Dispatcher(
            new Router(Routes::define(static function (Routes $r): void {
                $r->get('/ping', PlainHandler::class);
            })),
            responseFactory: self::responseFactory(),
        );

        $response = $dispatcher->handle(new ServerRequest('POST', '/ping'));

        static::assertSame(405, $response->getStatusCode());
        static::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testHandleWithoutAResponseFactoryThrows(): void
    {
        $dispatcher = new Dispatcher(new Router(Routes::define(static function (Routes $r): void {
            $r->get('/ping', PlainHandler::class);
        })));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/needs a ResponseFactoryInterface/');

        $dispatcher->handle(new ServerRequest('GET', '/nope'));
    }
}
