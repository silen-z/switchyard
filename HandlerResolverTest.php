<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Http\Found;
use SilenZ\Segmatch\Http\HandlerResolver;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\ConfigurableGuard;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureGuard;
use SilenZ\Segmatch\Tests\Http\Fixtures\NumericGuard;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\RequestMethodGuard;
use SilenZ\Segmatch\Tests\Http\Fixtures\TagMiddleware;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Which route a request reaches. Every route handler is an
 * {@see \SilenZ\Segmatch\Tests\Http\Fixtures\EchoHandler}, which answers with the `Found` it was given.
 */
final class HandlerResolverTest extends TestCase
{
    private static function resolver(
        Routes|Router $routes,
        ?ContainerInterface $container = null,
        ?RequestHandlerInterface $notFoundHandler = null,
    ): HandlerResolver {
        if ($routes instanceof Router) {
            return new HandlerResolver(
                $routes,
                new Psr17Factory(),
                $container ?? new EchoContainer(),
                $notFoundHandler,
            );
        }

        return new HandlerResolver(
            new Router($routes->compiled()),
            new Psr17Factory(),
            $container ?? new EchoContainer(),
            $notFoundHandler,
            registry: $routes->registry(),
        );
    }

    /**
     * @param callable(Routes): void $define
     */
    private static function router(callable $define): Routes
    {
        $routes = new Routes();
        $define($routes);

        return $routes;
    }

    private static function respond(HandlerResolver $resolver, ServerRequestInterface $request): ResponseInterface
    {
        return $resolver->resolve($request)->handle($request);
    }

    /**
     * The `Found` the route's handler was given, as it echoed it back.
     */
    private static function found(HandlerResolver $resolver, ServerRequestInterface $request): Found
    {
        $response = self::respond($resolver, $request);
        static::assertSame(200, $response->getStatusCode());

        /** @var array{params: array<string, string>, name: ?string, tags: list<string>} $fields */
        $fields = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        return new Found(...$fields);
    }

    /**
     * The handler identifier of the route the request reached.
     */
    private static function handlerOf(HandlerResolver $resolver, ServerRequestInterface $request): string
    {
        $response = self::respond($resolver, $request);
        static::assertSame(200, $response->getStatusCode());

        return $response->getHeaderLine('X-Handler');
    }

    private static function assertNotFound(HandlerResolver $resolver, ServerRequestInterface $request): void
    {
        static::assertSame(404, self::respond($resolver, $request)->getStatusCode());
    }

    private static function assertMethodNotAllowed(
        string $allow,
        HandlerResolver $resolver,
        ServerRequestInterface $request,
    ): void {
        $response = self::respond($resolver, $request);

        static::assertSame(405, $response->getStatusCode());
        static::assertSame($allow, $response->getHeaderLine('Allow'));
    }

    private static function apiResolver(?RequestHandlerInterface $notFoundHandler = null): HandlerResolver
    {
        return self::resolver(
            self::router(static function (Routes $r): void {
                $api = $r->group('/api')->middleware('api')->tag('json');
                $api->get('/users/{id}', 'show')->name('users.show')->middleware('auth')->tag('public');
                $api->put('/users/{id}', 'update');
                $api->get('/beta', 'beta')->guard(new FeatureGuard('beta'));

                $r->get('/ping', 'ping');
                $r->map(['HEAD'], '/ping', 'ping-head');
                $r->post('/login', 'login');
                $r->any('/webhooks/{provider}', 'webhook');
            }),
            new EchoContainer(['api' => new TagMiddleware('api'), 'auth' => new TagMiddleware('auth')]),
            $notFoundHandler,
        );
    }

    public function testFoundCarriesTheRoute(): void
    {
        static::assertEquals(
            new Found(params: ['id' => '42'], name: 'users.show', tags: ['json', 'public']),
            self::found(self::apiResolver(), new ServerRequest('get', '/api/users/42')),
        );
    }

    public function testRunsTheRoutesHandlerInsideItsMiddleware(): void
    {
        $response = self::respond(self::apiResolver(), new ServerRequest('GET', '/api/users/42'));

        static::assertSame('show', $response->getHeaderLine('X-Handler'));
        // The group's middleware is outermost, so it tags the response last.
        static::assertSame('auth,api', $response->getHeaderLine('X-Trail'));
    }

    public function testAnyRoutesTakeEveryMethod(): void
    {
        static::assertSame('webhook', self::handlerOf(
            self::apiResolver(),
            new ServerRequest('PURGE', '/webhooks/github'),
        ));
    }

    public function testHeadMatchesGetRoutesWithoutTheBody(): void
    {
        $response = self::respond(self::apiResolver(), new ServerRequest('HEAD', '/api/users/42'));

        static::assertSame(200, $response->getStatusCode());
        // The GET route's handler ran and its headers came through...
        static::assertSame('show', $response->getHeaderLine('X-Handler'));
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        // ...but a HEAD response has no body.
        static::assertSame('', (string) $response->getBody());
    }

    public function testHeadRequestsAreNeverRewrittenToGet(): void
    {
        $resolver = self::resolver(self::router(static function (Routes $r): void {
            $r->get('/head-only', 'head-only')->guard(new RequestMethodGuard('HEAD'));
        }));

        $response = self::respond($resolver, new ServerRequest('HEAD', '/head-only'));

        // The guard accepted, so it saw HEAD; so does the handler.
        static::assertSame('head-only', $response->getHeaderLine('X-Handler'));
        static::assertSame('HEAD', $response->getHeaderLine('X-Method'));
    }

    public function testHeadRoutesWinOverGetRoutes(): void
    {
        $response = self::respond(self::apiResolver(), new ServerRequest('HEAD', '/ping'));

        static::assertSame('ping-head', $response->getHeaderLine('X-Handler'));
        // Even an explicit HEAD route's response loses its body.
        static::assertSame('', (string) $response->getBody());
    }

    public function testEveryHeadResponseLosesItsBody(): void
    {
        $resolver = self::apiResolver(notFoundHandler: new EchoHandler());

        // An any() route, which writes a body whatever the method.
        $any = self::respond($resolver, new ServerRequest('HEAD', '/webhooks/github'));
        static::assertSame('webhook', $any->getHeaderLine('X-Handler'));
        static::assertSame('', (string) $any->getBody());

        // An application's own not-found handler, which writes a body too.
        $notFound = self::respond($resolver, new ServerRequest('HEAD', '/nope'));
        static::assertSame('application/json', $notFound->getHeaderLine('Content-Type'));
        static::assertSame('', (string) $notFound->getBody());

        // The same handler still writes its body for other methods.
        static::assertSame('null', (string) self::respond($resolver, new ServerRequest('GET', '/nope'))->getBody());
    }

    public function testMethodNotAllowedListsTheAllowedMethods(): void
    {
        self::assertMethodNotAllowed(
            'GET, PUT, HEAD',
            self::apiResolver(),
            new ServerRequest('DELETE', '/api/users/42'),
        );
        self::assertMethodNotAllowed('POST', self::apiResolver(), new ServerRequest('HEAD', '/login'));
    }

    public function testNotFound(): void
    {
        self::assertNotFound(self::apiResolver(), new ServerRequest('GET', '/nope'));
    }

    public function testRoutesRejectedByTheirOwnGuardsAreNotFound(): void
    {
        $resolver = self::apiResolver();

        self::assertNotFound($resolver, new ServerRequest('GET', '/api/beta'));
        static::assertSame('beta', self::handlerOf($resolver, new ServerRequest(
            'GET',
            '/api/beta',
        )->withAttribute('features', [
            'beta' => true,
        ])));
    }

    public function testRoutesWithoutMethodsOrGuardsAlwaysApply(): void
    {
        $resolver = self::resolver(new Router(static fn(): array => [new RouteDefinition('/raw', [
            'handler' => 'raw',
        ])]));

        static::assertSame('raw', self::handlerOf($resolver, new ServerRequest('DELETE', '/raw')));
    }

    private static function routingResolver(): HandlerResolver
    {
        return self::resolver(self::router(static function (Routes $r): void {
            $r->get('/users', 'list');
            $r->post('/users', 'create');
            $r->post('/users/new', 'create-form');
            $r->get('/users/{id}', 'show')->guard(new NumericGuard('id'));
            $r->get('/users/{slug}', 'by-slug');
            $r->map(['PUT', 'PATCH'], '/users/{id}', 'update')->guard(new NumericGuard('id'));
            $r->get('/{path+}', 'frontend');
        }));
    }

    public function testFallsThroughToARouteThatAcceptsTheMethod(): void
    {
        $request = new ServerRequest('GET', '/users/new');

        static::assertSame('by-slug', self::handlerOf(self::routingResolver(), $request));
        static::assertSame(['slug' => 'new'], self::found(self::routingResolver(), $request)->params);
    }

    public function testPatternsChooseBetweenRoutesOfTheSameShape(): void
    {
        $resolver = self::routingResolver();

        static::assertSame('show', self::handlerOf($resolver, new ServerRequest('GET', '/users/42')));
        static::assertSame('by-slug', self::handlerOf($resolver, new ServerRequest('GET', '/users/john')));
    }

    public function testHeadFallsBackToTheFirstGetRouteWhoseGuardsAccept(): void
    {
        $resolver = self::routingResolver();

        // "/users/42": show comes first and its numeric guard accepts.
        $show = self::respond($resolver, new ServerRequest('HEAD', '/users/42'));
        static::assertSame('show', $show->getHeaderLine('X-Handler'));

        // "/users/john": show's guard rejects, so the next GET route, by-slug, takes it.
        $bySlug = self::respond($resolver, new ServerRequest('HEAD', '/users/john'));
        static::assertSame('by-slug', $bySlug->getHeaderLine('X-Handler'));
    }

    public function testAllowedMethodsComeFromRoutesRejectedOnlyForTheirMethod(): void
    {
        $resolver = self::routingResolver();

        self::assertMethodNotAllowed('GET, POST, HEAD', $resolver, new ServerRequest('DELETE', '/users'));

        // "/users/7": show and update match the pattern, by-slug matches too, the catch-all is GET.
        self::assertMethodNotAllowed('GET, PUT, PATCH, HEAD', $resolver, new ServerRequest('DELETE', '/users/7'));

        // "/users/john": show and update's numeric guard fails, so only by-slug and the catch-all count.
        self::assertMethodNotAllowed('GET, HEAD', $resolver, new ServerRequest('DELETE', '/users/john'));
    }

    public function testRouteRejectedForAnotherReasonDoesNotCountAsAllowed(): void
    {
        $resolver = self::resolver(self::router(static function (Routes $r): void {
            $r->get('/beta', 'beta')->guard(new FeatureGuard('beta'));
        }));

        self::assertNotFound($resolver, new ServerRequest('POST', '/beta'));
    }

    public function testGuardsAreResolvedFromTheContainer(): void
    {
        $routes = self::router(static function (Routes $r): void {
            $r->get('/locked', 'locked')->guard(ConfigurableGuard::class);
        });

        $allowing = self::resolver($routes, new EchoContainer([
            ConfigurableGuard::class => new ConfigurableGuard(accepts: true),
        ]));
        static::assertSame('locked', self::handlerOf($allowing, new ServerRequest('GET', '/locked')));

        $blocking = self::resolver($routes, new EchoContainer([
            ConfigurableGuard::class => new ConfigurableGuard(accepts: false),
        ]));
        self::assertNotFound($blocking, new ServerRequest('GET', '/locked'));
    }

    public function testHandlerMiddlewareAndGuardMayBeRealInstances(): void
    {
        $routes = new Routes();
        $routes
            ->get('/x', new PlainHandler())
            ->middleware(new TagMiddleware('instance'))
            ->guard(new ConfigurableGuard(accepts: true));

        $resolver = new HandlerResolver(
            new Router($routes->compiled()),
            new Psr17Factory(),
            registry: $routes->registry(),
        );

        $response = self::respond($resolver, new ServerRequest('GET', '/x'));

        static::assertSame(204, $response->getStatusCode());
        static::assertSame('instance', $response->getHeaderLine('X-Trail'));
    }

    public function testGuardInstanceRejectsJustLikeAClassWould(): void
    {
        $routes = new Routes();
        $routes->get('/x', new PlainHandler())->guard(new ConfigurableGuard(accepts: false));

        $resolver = new HandlerResolver(
            new Router($routes->compiled()),
            new Psr17Factory(),
            registry: $routes->registry(),
        );

        self::assertNotFound($resolver, new ServerRequest('GET', '/x'));
    }

    public function testTheSameGuardClassMayBeUsedTwiceWithDifferentInstances(): void
    {
        $routes = new Routes();
        $routes
            ->get('/users/{id}/posts/{postId}', new PlainHandler())
            ->guard(new NumericGuard('id'))
            ->guard(new NumericGuard('postId'));

        $resolver = new HandlerResolver(
            new Router($routes->compiled()),
            new Psr17Factory(),
            registry: $routes->registry(),
        );

        $response = self::respond($resolver, new ServerRequest('GET', '/users/42/posts/7'));
        static::assertSame(204, $response->getStatusCode());

        // The second guard rejects: "posts/new" isn't numeric.
        self::assertNotFound($resolver, new ServerRequest('GET', '/users/42/posts/new'));
    }
}
