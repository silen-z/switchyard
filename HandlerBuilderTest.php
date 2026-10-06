<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Http\Found;
use SilenZ\Segmatch\Http\HandlerBuilder;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\ArrayRouteCache;
use SilenZ\Segmatch\Tests\Http\Fixtures\ConfigurableRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoContainer;
use SilenZ\Segmatch\Tests\Http\Fixtures\EchoHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\NumericRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\PlainHandler;
use SilenZ\Segmatch\Tests\Http\Fixtures\RequestMethodRouteFilter;
use SilenZ\Segmatch\Tests\Http\Fixtures\TagMiddleware;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Which route a request reaches. Every route handler is an
 * {@see \SilenZ\Segmatch\Tests\Http\Fixtures\EchoHandler}, which answers with the `Found` it was given.
 */
final class HandlerBuilderTest extends TestCase
{
    /**
     * @param callable(Routes): void $define
     */
    private static function builder(callable $define, ?ContainerInterface $container = null): HandlerBuilder
    {
        $routes = new Routes();
        $define($routes);

        return new HandlerBuilder($container ?? new EchoContainer(), new Router($routes->table()));
    }

    private static function respond(
        HandlerBuilder $builder,
        ServerRequestInterface $request,
        ?RequestHandlerInterface $notFoundHandler = null,
    ): ResponseInterface {
        return $builder->build($request, $notFoundHandler)->handle($request);
    }

    /**
     * The `Found` the route's handler was given, as it echoed it back.
     */
    private static function found(HandlerBuilder $builder, ServerRequestInterface $request): Found
    {
        $response = self::respond($builder, $request);
        static::assertSame(200, $response->getStatusCode());

        /** @var array{params: array<string, string>, name: ?string, tags: list<string>} $fields */
        $fields = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        return new Found(...$fields);
    }

    /**
     * The handler identifier of the route the request reached.
     */
    private static function handlerOf(HandlerBuilder $builder, ServerRequestInterface $request): string
    {
        $response = self::respond($builder, $request);
        static::assertSame(200, $response->getStatusCode());

        return $response->getHeaderLine('X-Handler');
    }

    private static function assertNotFound(HandlerBuilder $builder, ServerRequestInterface $request): void
    {
        static::assertSame(404, self::respond($builder, $request)->getStatusCode());
    }

    private static function assertMethodNotAllowed(
        string $allow,
        HandlerBuilder $builder,
        ServerRequestInterface $request,
    ): void {
        $response = self::respond($builder, $request);

        static::assertSame(405, $response->getStatusCode());
        static::assertSame($allow, $response->getHeaderLine('Allow'));
    }

    private static function apiBuilder(): HandlerBuilder
    {
        return self::builder(
            static function (Routes $r): void {
                $api = $r->group('/api')->middleware('api')->tag('json');
                $api->get('/users/{id}', 'show')->name('users.show')->middleware('auth')->tag('public');
                $api->put('/users/{id}', 'update');
                $api->get('/beta', 'beta')->filter(new FeatureRouteFilter('beta'));

                $r->get('/ping', 'ping');
                $r->map(['HEAD'], '/ping', 'ping-head');
                $r->post('/login', 'login');
                $r->any('/webhooks/{provider}', 'webhook');
            },
            new EchoContainer(['api' => new TagMiddleware('api'), 'auth' => new TagMiddleware('auth')]),
        );
    }

    public function testFoundCarriesTheRoute(): void
    {
        static::assertEquals(
            new Found(params: ['id' => '42'], name: 'users.show', tags: ['json', 'public']),
            self::found(self::apiBuilder(), new ServerRequest('get', '/api/users/42')),
        );
    }

    public function testRunsTheRoutesHandlerInsideItsMiddleware(): void
    {
        $response = self::respond(self::apiBuilder(), new ServerRequest('GET', '/api/users/42'));

        static::assertSame('show', $response->getHeaderLine('X-Handler'));
        // The group's middleware is outermost, so it tags the response last.
        static::assertSame('auth,api', $response->getHeaderLine('X-Trail'));
    }

    public function testAnyRoutesTakeEveryMethod(): void
    {
        static::assertSame('webhook', self::handlerOf(
            self::apiBuilder(),
            new ServerRequest('PURGE', '/webhooks/github'),
        ));
    }

    public function testHeadMatchesGetRoutesWithoutTheBody(): void
    {
        $response = self::respond(self::apiBuilder(), new ServerRequest('HEAD', '/api/users/42'));

        static::assertSame(200, $response->getStatusCode());
        // The GET route's handler ran and its headers came through...
        static::assertSame('show', $response->getHeaderLine('X-Handler'));
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        // ...but a HEAD response has no body.
        static::assertSame('', (string) $response->getBody());
    }

    public function testHeadRequestsAreNeverRewrittenToGet(): void
    {
        $builder = self::builder(static function (Routes $r): void {
            $r->get('/head-only', 'head-only')->filter(new RequestMethodRouteFilter('HEAD'));
        });

        $response = self::respond($builder, new ServerRequest('HEAD', '/head-only'));

        // The filter accepted, so it saw HEAD; so does the handler.
        static::assertSame('head-only', $response->getHeaderLine('X-Handler'));
        static::assertSame('HEAD', $response->getHeaderLine('X-Method'));
    }

    public function testHeadRoutesWinOverGetRoutes(): void
    {
        $response = self::respond(self::apiBuilder(), new ServerRequest('HEAD', '/ping'));

        static::assertSame('ping-head', $response->getHeaderLine('X-Handler'));
        // Even an explicit HEAD route's response loses its body.
        static::assertSame('', (string) $response->getBody());
    }

    public function testEveryHeadResponseLosesItsBody(): void
    {
        $builder = self::apiBuilder();
        $notFoundHandler = new EchoHandler();

        // An any() route, which writes a body whatever the method.
        $any = self::respond($builder, new ServerRequest('HEAD', '/webhooks/github'));
        static::assertSame('webhook', $any->getHeaderLine('X-Handler'));
        static::assertSame('', (string) $any->getBody());

        // An application's own not-found handler, which writes a body too.
        $notFound = self::respond($builder, new ServerRequest('HEAD', '/nope'), $notFoundHandler);
        static::assertSame('application/json', $notFound->getHeaderLine('Content-Type'));
        static::assertSame('', (string) $notFound->getBody());

        // The same handler still writes its body for other methods.
        static::assertSame(
            'null',
            (string) self::respond($builder, new ServerRequest('GET', '/nope'), $notFoundHandler)->getBody(),
        );
    }

    public function testMethodNotAllowedListsTheAllowedMethods(): void
    {
        self::assertMethodNotAllowed(
            'GET, PUT, HEAD',
            self::apiBuilder(),
            new ServerRequest('DELETE', '/api/users/42'),
        );
        self::assertMethodNotAllowed('POST', self::apiBuilder(), new ServerRequest('HEAD', '/login'));
    }

    public function testNotFound(): void
    {
        self::assertNotFound(self::apiBuilder(), new ServerRequest('GET', '/nope'));
    }

    public function testRoutesRejectedByTheirOwnFiltersAreNotFound(): void
    {
        $builder = self::apiBuilder();

        self::assertNotFound($builder, new ServerRequest('GET', '/api/beta'));
        static::assertSame('beta', self::handlerOf($builder, new ServerRequest(
            'GET',
            '/api/beta',
        )->withAttribute('features', [
            'beta' => true,
        ])));
    }

    public function testRoutesWithoutMethodsOrFiltersAlwaysApply(): void
    {
        // any() produces a route with no 'methods' and no 'filters' metadata, same as a raw definition would.
        $builder = self::builder(static fn(Routes $r) => $r->any('/raw', 'raw'));

        static::assertSame('raw', self::handlerOf($builder, new ServerRequest('DELETE', '/raw')));
    }

    private static function routingBuilder(): HandlerBuilder
    {
        return self::builder(static function (Routes $r): void {
            $r->get('/users', 'list');
            $r->post('/users', 'create');
            $r->post('/users/new', 'create-form');
            $r->get('/users/{id}', 'show')->filter(new NumericRouteFilter('id'));
            $r->get('/users/{slug}', 'by-slug');
            $r->map(['PUT', 'PATCH'], '/users/{id}', 'update')->filter(new NumericRouteFilter('id'));
            $r->get('/{path+}', 'frontend');
        });
    }

    public function testFallsThroughToARouteThatAcceptsTheMethod(): void
    {
        $request = new ServerRequest('GET', '/users/new');

        static::assertSame('by-slug', self::handlerOf(self::routingBuilder(), $request));
        static::assertSame(['slug' => 'new'], self::found(self::routingBuilder(), $request)->params);
    }

    public function testPatternsChooseBetweenRoutesOfTheSameShape(): void
    {
        $builder = self::routingBuilder();

        static::assertSame('show', self::handlerOf($builder, new ServerRequest('GET', '/users/42')));
        static::assertSame('by-slug', self::handlerOf($builder, new ServerRequest('GET', '/users/john')));
    }

    public function testHeadFallsBackToTheFirstGetRouteWhoseFiltersAccept(): void
    {
        $builder = self::routingBuilder();

        // "/users/42": show comes first and its numeric filter accepts.
        $show = self::respond($builder, new ServerRequest('HEAD', '/users/42'));
        static::assertSame('show', $show->getHeaderLine('X-Handler'));

        // "/users/john": show's filter rejects, so the next GET route, by-slug, takes it.
        $bySlug = self::respond($builder, new ServerRequest('HEAD', '/users/john'));
        static::assertSame('by-slug', $bySlug->getHeaderLine('X-Handler'));
    }

    public function testAllowedMethodsComeFromRoutesRejectedOnlyForTheirMethod(): void
    {
        $builder = self::routingBuilder();

        self::assertMethodNotAllowed('GET, POST, HEAD', $builder, new ServerRequest('DELETE', '/users'));

        // "/users/7": show and update match the pattern, by-slug matches too, the catch-all is GET.
        self::assertMethodNotAllowed('GET, PUT, PATCH, HEAD', $builder, new ServerRequest('DELETE', '/users/7'));

        // "/users/john": show and update's numeric filter fails, so only by-slug and the catch-all count.
        self::assertMethodNotAllowed('GET, HEAD', $builder, new ServerRequest('DELETE', '/users/john'));
    }

    public function testRouteRejectedForAnotherReasonDoesNotCountAsAllowed(): void
    {
        $builder = self::builder(static function (Routes $r): void {
            $r->get('/beta', 'beta')->filter(new FeatureRouteFilter('beta'));
        });

        self::assertNotFound($builder, new ServerRequest('POST', '/beta'));
    }

    public function testFiltersAreResolvedFromTheContainer(): void
    {
        $define = static function (Routes $r): void {
            $r->get('/locked', 'locked')->filter(ConfigurableRouteFilter::class);
        };

        $allowing = self::builder($define, new EchoContainer([
            ConfigurableRouteFilter::class => new ConfigurableRouteFilter(accepts: true),
        ]));
        static::assertSame('locked', self::handlerOf($allowing, new ServerRequest('GET', '/locked')));

        $blocking = self::builder($define, new EchoContainer([
            ConfigurableRouteFilter::class => new ConfigurableRouteFilter(accepts: false),
        ]));
        self::assertNotFound($blocking, new ServerRequest('GET', '/locked'));
    }

    public function testHandlerMiddlewareAndFilterMayBeRealInstances(): void
    {
        $builder = self::builder(static function (Routes $r): void {
            $r
                ->get('/x', new PlainHandler())
                ->middleware(new TagMiddleware('instance'))
                ->filter(new ConfigurableRouteFilter(accepts: true));
        });

        $response = self::respond($builder, new ServerRequest('GET', '/x'));

        static::assertSame(204, $response->getStatusCode());
        static::assertSame('instance', $response->getHeaderLine('X-Trail'));
    }

    public function testFilterInstanceRejectsJustLikeAClassWould(): void
    {
        $builder = self::builder(static function (Routes $r): void {
            $r->get('/x', new PlainHandler())->filter(new ConfigurableRouteFilter(accepts: false));
        });

        self::assertNotFound($builder, new ServerRequest('GET', '/x'));
    }

    public function testTheSameFilterClassMayBeUsedTwiceWithDifferentInstances(): void
    {
        $builder = self::builder(static function (Routes $r): void {
            $r
                ->get('/users/{id}/posts/{postId}', new PlainHandler())
                ->filter(new NumericRouteFilter('id'))
                ->filter(new NumericRouteFilter('postId'));
        });

        $response = self::respond($builder, new ServerRequest('GET', '/users/42/posts/7'));
        static::assertSame(204, $response->getStatusCode());

        // The second filter rejects: "posts/new" isn't numeric.
        self::assertNotFound($builder, new ServerRequest('GET', '/users/42/posts/new'));
    }

    public function testEachBuilderHasItsOwnTreeAndRegistry(): void
    {
        $first = self::builder(static function (Routes $r): void {
            $r->get('/x', new PlainHandler())->filter(new ConfigurableRouteFilter(accepts: true));
        });
        $second = self::builder(static function (Routes $r): void {
            $r->get('/x', new PlainHandler())->filter(new ConfigurableRouteFilter(accepts: false));
        });

        // The two declarations wrap instances at the same ids, but each resolves its own.
        static::assertSame(204, self::respond($first, new ServerRequest('GET', '/x'))->getStatusCode());
        self::assertNotFound($second, new ServerRequest('GET', '/x'));
    }

    public function testRegistryIdsAreRebuiltForEachDeclarationEvenOnAWarmCache(): void
    {
        $cache = new ArrayRouteCache();

        // Cold: the handler instance is wrapped into this declaration's registry as an id, which is
        // what ends up in the compiled routes.
        $cold = new Routes();
        $cold->get('/x', new PlainHandler());
        (new HandlerBuilder(new EchoContainer(), new Router($cold->table('routes'), $cache)))
            ->build(new ServerRequest('GET', '/x'));

        // Warm: a fresh declaration, with its own fresh instance at the same id, answers from the
        // cached routes and resolves its own instance for that id — its `Router` carries this same
        // declaration's registry automatically, never the cold one's.
        $warm = new Routes();
        $warm->get('/x', new PlainHandler());
        $builder = new HandlerBuilder(new EchoContainer(), new Router($warm->table('routes'), $cache));

        $response = $builder->build(new ServerRequest('GET', '/x'))->handle(new ServerRequest('GET', '/x'));

        static::assertSame(204, $response->getStatusCode());
    }
}
