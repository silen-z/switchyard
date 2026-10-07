<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\HandlerBuilder;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoContainer;

/**
 * {@see HandlerBuilder::build()}'s redirect for a path with no route of its own but a trailing-slash
 * counterpart that does.
 */
final class TrailingSlashTest extends TestCase
{
    /**
     * @param callable(Routes): void $define
     */
    private static function respond(callable $define, ServerRequestInterface $request): ResponseInterface
    {
        $routes = new Routes();
        $define($routes);

        $builder = new HandlerBuilder(new EchoContainer(), new Router($routes->table()));

        return $builder->build($request)->handle($request);
    }

    public function testRedirectsToAnExistingRouteWithoutTheTrailingSlash(): void
    {
        $response = self::respond(static fn(Routes $r) => $r->get('/foo', 'foo'), new ServerRequest('GET', '/foo/'));

        static::assertSame(308, $response->getStatusCode());
        static::assertSame('/foo', $response->getHeaderLine('Location'));
    }

    public function testRedirectsToAnExistingRouteWithTheTrailingSlashAdded(): void
    {
        $response = self::respond(static fn(Routes $r) => $r->get('/foo/', 'foo'), new ServerRequest('GET', '/foo'));

        static::assertSame(308, $response->getStatusCode());
        static::assertSame('/foo/', $response->getHeaderLine('Location'));
    }

    public function testPreservesTheQueryString(): void
    {
        $response = self::respond(
            static fn(Routes $r) => $r->get('/foo', 'foo'),
            new ServerRequest('GET', '/foo/?a=1&b=2'),
        );

        static::assertSame('/foo?a=1&b=2', $response->getHeaderLine('Location'));
    }

    public function testDistinctRoutesForBothFormsAreNeverRedirected(): void
    {
        $define = static function (Routes $r): void {
            $r->get('/foo', 'without-slash');
            $r->get('/foo/', 'with-slash');
        };

        static::assertSame(200, self::respond($define, new ServerRequest('GET', '/foo'))->getStatusCode());
        static::assertSame(200, self::respond($define, new ServerRequest('GET', '/foo/'))->getStatusCode());
    }

    public function testRootHasNoCounterpartToRedirectTo(): void
    {
        $response = self::respond(static fn(Routes $r) => $r->get('/x', 'x'), new ServerRequest('GET', '/'));

        static::assertSame(404, $response->getStatusCode());
    }

    public function testDoesNotRedirectWhenTheCounterpartDoesNotAcceptTheMethod(): void
    {
        // "/foo/" exists, but only for POST: a GET to "/foo" has nowhere matching to redirect to.
        $response = self::respond(static fn(Routes $r) => $r->post('/foo/', 'foo'), new ServerRequest('GET', '/foo'));

        static::assertSame(404, $response->getStatusCode());
    }

    public function testAHeadRequestsRedirectHasNoBody(): void
    {
        $response = self::respond(static fn(Routes $r) => $r->get('/foo', 'foo'), new ServerRequest('HEAD', '/foo/'));

        static::assertSame(308, $response->getStatusCode());
        static::assertSame('/foo', $response->getHeaderLine('Location'));
        static::assertSame('', (string) $response->getBody());
    }

    public function testAnExistingPathNeverRedirectsEvenIfItsMethodIsNotAllowed(): void
    {
        // "/foo" exists (GET only), so the 405 for a DELETE to it takes precedence over considering
        // "/foo/" at all: NoMatch::$rejected isn't empty.
        $response = self::respond(static fn(Routes $r) => $r->get('/foo', 'foo'), new ServerRequest('DELETE', '/foo'));

        static::assertSame(405, $response->getStatusCode());
    }
}
