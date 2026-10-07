<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\HandlerBuilder;
use SilenZ\Switchyard\Routes;
use SilenZ\Switchyard\Tests\Fixtures\CorsMiddleware;
use SilenZ\Switchyard\Tests\Fixtures\EchoContainer;
use SilenZ\Switchyard\Tests\Fixtures\FeatureRouteFilter;
use SilenZ\Switchyard\Tests\Fixtures\PlainHandler;

/**
 * CORS declared as middleware on the root `Http\Routes`, so it wraps every outcome of
 * `$builder->handler($request)->handle($request)`, not just matched routes. It reads the allowed
 * methods from the response's `Allow` header instead of the `MethodNotAllowed` request attribute,
 * which only exists inside the resolver's own stack (see `Fixtures\CorsMiddleware`).
 */
final class CorsTest extends TestCase
{
    private const string ORIGIN = 'https://app.example';

    private static function respond(ServerRequestInterface $request): ResponseInterface
    {
        $routes = new Routes();
        $routes->middleware(new CorsMiddleware([self::ORIGIN], headers: ['Content-Type'], maxAge: 300));
        $routes->get('/users', PlainHandler::class);
        $routes->post('/users', PlainHandler::class);
        $routes->put('/users', PlainHandler::class)->filter(new FeatureRouteFilter('bulk-edit'));
        $routes->get('/reports', PlainHandler::class);
        $routes->map(['OPTIONS'], '/reports', PlainHandler::class);

        $builder = new HandlerBuilder(new EchoContainer(), new Router($routes->table()));

        return $builder->build($request)->handle($request);
    }

    private static function preflight(string $path, string $origin = self::ORIGIN): ServerRequest
    {
        return new ServerRequest('OPTIONS', $path, [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type',
        ]);
    }

    public function testPreflightGetsTheAllowedMethods(): void
    {
        $response = self::respond(self::preflight('/users'));

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        static::assertSame('GET, POST, HEAD', $response->getHeaderLine('Access-Control-Allow-Methods'));
        static::assertSame('Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        static::assertSame('300', $response->getHeaderLine('Access-Control-Max-Age'));
        static::assertSame('Origin', $response->getHeaderLine('Vary'));
        // The default OPTIONS answer underneath is still there.
        static::assertSame('GET, POST, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testPreflightAllowedMethodsRespectFilters(): void
    {
        // PUT /users only exists while the feature is on.
        $on = self::respond(self::preflight('/users')->withAttribute('features', ['bulk-edit' => true]));

        static::assertSame('GET, POST, PUT, HEAD', $on->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testActualRequestsGetTheAllowOriginHeader(): void
    {
        $response = self::respond(new ServerRequest('POST', '/users', ['Origin' => self::ORIGIN]));

        static::assertSame(204, $response->getStatusCode());
        static::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        static::assertSame('Origin', $response->getHeaderLine('Vary'));
        static::assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
    }

    public function testOtherOriginsAndSameOriginRequestsGetNoCorsHeaders(): void
    {
        $foreign = self::respond(self::preflight('/users', 'https://evil.example'));
        static::assertFalse($foreign->hasHeader('Access-Control-Allow-Origin'));
        static::assertFalse($foreign->hasHeader('Access-Control-Allow-Methods'));

        $sameOrigin = self::respond(new ServerRequest('GET', '/users'));
        static::assertFalse($sameOrigin->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testPreflightForAnUnknownPathIsNotAllowed(): void
    {
        $response = self::respond(self::preflight('/nope'));

        static::assertSame(404, $response->getStatusCode());
        static::assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
    }

    public function testRoutesThatAnswerOptionsThemselvesAreLeftAlone(): void
    {
        $response = self::respond(self::preflight('/reports'));

        // The explicit OPTIONS route answered, so there's no Allow header to build the preflight from.
        static::assertSame(204, $response->getStatusCode());
        static::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        static::assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
    }
}
