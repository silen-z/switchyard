<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\ErrorMiddleware;
use SilenZ\Segmatch\Http\HandlerBuilder;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\ThrowingHandler;

/**
 * {@see ErrorMiddleware} declared as middleware on the root `Http\Routes`, so it wraps a route
 * handler's own exception as well as a request no route applies to.
 */
final class ErrorMiddlewareTest extends TestCase
{
    private static function respond(ServerRequestInterface $request): ResponseInterface
    {
        $routes = new Routes();
        $routes->middleware(ErrorMiddleware::class);
        $routes->get('/boom', ThrowingHandler::class);
        $routes->get('/ok', PlainHandler::class);

        $builder = new HandlerBuilder(new EchoContainer(), new Router($routes->table()));

        return $builder->build($request)->handle($request);
    }

    public function testCatchesAThrowingHandler(): void
    {
        $response = self::respond(new ServerRequest('GET', '/boom'));

        static::assertSame(500, $response->getStatusCode());
        static::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        static::assertSame('Server error', (string) $response->getBody());
    }

    public function testLeavesASuccessfulResponseAlone(): void
    {
        $response = self::respond(new ServerRequest('GET', '/ok'));

        static::assertSame(204, $response->getStatusCode());
    }

    public function testAlsoWrapsTheNotFoundFallback(): void
    {
        $response = self::respond(new ServerRequest('GET', '/missing'));

        static::assertSame(404, $response->getStatusCode());
    }
}
