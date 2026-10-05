<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SilenZ\Segmatch\Http\HandlerResolver;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\CorsMiddleware;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;

/**
 * CORS as application middleware ({@see HandlerResolver::addMiddleware()}), using the default OPTIONS
 * answer's `MethodNotAllowed` attribute for preflights.
 */
final class CorsTest extends TestCase
{
    private const string ORIGIN = 'https://app.example';

    private static function respond(ServerRequest $request): ResponseInterface
    {
        $routes = new Routes();
        $routes->get('/users', PlainHandler::class);
        $routes->post('/users', PlainHandler::class);
        $routes->put('/users', PlainHandler::class)->filter(new FeatureFilter('bulk-edit'));
        $routes->get('/reports', PlainHandler::class);
        $routes->map(['OPTIONS'], '/reports', PlainHandler::class);

        $resolver = new HandlerResolver(
            new Router($routes->compiled()),
            new Psr17Factory(),
            registry: $routes->registry(),
        );
        $resolver->addMiddleware(new CorsMiddleware([self::ORIGIN], headers: ['Content-Type'], maxAge: 300));

        return $resolver->resolve($request)->handle($request);
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

        // The explicit OPTIONS route answered, so there's no MethodNotAllowed to build the preflight from.
        static::assertSame(204, $response->getStatusCode());
        static::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        static::assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
    }
}
