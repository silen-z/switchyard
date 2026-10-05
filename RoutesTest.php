<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use ArrayObject;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\CallableRouteTable;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Http\MethodNotAllowed;
use SilenZ\Segmatch\Http\Registry;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureRouteFilter;
use stdClass;

use function preg_quote;

final class RoutesTest extends TestCase
{
    /**
     * @param callable(Routes): void $define
     */
    private static function router(callable $define): Router
    {
        $routes = new Routes(new Registry());
        $define($routes);

        return new Router($routes->table());
    }

    /**
     * Metadata of the route a request reaches, choosing between routes by HTTP method.
     *
     * @return array<string, mixed>|null
     */
    private static function find(Router $router, string $method, string $path): ?array
    {
        $result = $router->match($path, static fn(RouteMatch $match): bool => MethodNotAllowed::accepts(
            $match->route,
            $method,
        ));

        if (!$result instanceof RouteMatch) {
            return null;
        }

        /** @var array<string, mixed> */
        return $result->route;
    }

    public function testVerbHelpersDeclareRoutesPerMethod(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/users', 'list');
            $r->post('/users', 'create');
            $r->put('/users/{id}', 'replace');
            $r->patch('/users/{id}', 'update');
            $r->delete('/users/{id}', 'delete');
            $r->options('/users', 'options');
            $r->map(['get', 'HEAD', 'Get'], '/health', 'health');
            $r->any('/webhooks/{provider}', 'webhook');
        });

        static::assertSame(
            ['handler' => 'list', 'middleware' => [], 'methods' => ['GET']],
            self::find($router, 'GET', '/users'),
        );
        static::assertSame('create', self::find($router, 'POST', '/users')['handler'] ?? null);
        static::assertSame('replace', self::find($router, 'PUT', '/users/1')['handler'] ?? null);
        static::assertSame('update', self::find($router, 'PATCH', '/users/1')['handler'] ?? null);
        static::assertSame('delete', self::find($router, 'DELETE', '/users/1')['handler'] ?? null);
        static::assertSame('options', self::find($router, 'OPTIONS', '/users')['handler'] ?? null);
        static::assertSame(['GET', 'HEAD'], self::find($router, 'HEAD', '/health')['methods'] ?? null);
        static::assertSame(
            ['handler' => 'webhook', 'middleware' => []],
            self::find($router, 'PURGE', '/webhooks/github'),
        );
    }

    public function testGroupsPrefixPathsAndInheritMiddlewareOutermostFirst(): void
    {
        $router = self::router(static function (Routes $r): void {
            $api = $r->group('/api')->middleware('api');
            $api->group()->middleware(['guest'])->post('/login', 'login');

            $auth = $api->group()->middleware('auth');
            $auth->get('/users/{id}', 'user');
            $auth->group('/admin')->middleware('admin')->get('/stats', 'stats')->middleware(['audit', 'log']);
        });

        static::assertSame(['api', 'guest'], self::find($router, 'POST', '/api/login')['middleware'] ?? null);
        static::assertSame(['api', 'auth'], self::find($router, 'GET', '/api/users/1')['middleware'] ?? null);
        static::assertSame(
            ['api', 'auth', 'admin', 'audit', 'log'],
            self::find($router, 'GET', '/api/admin/stats')['middleware'] ?? null,
        );
    }

    public function testGroupCallsMayComeInAnyOrder(): void
    {
        $router = self::router(static function (Routes $r): void {
            $group = $r->group('/api');
            $group->get('/a', 'a');
            $group->middleware('late');
            $group->get('/b', 'b');
        });

        static::assertSame(['late'], self::find($router, 'GET', '/api/a')['middleware'] ?? null);
        static::assertSame(['late'], self::find($router, 'GET', '/api/b')['middleware'] ?? null);
    }

    public function testRouteOnTheGroupPrefixItself(): void
    {
        $router = self::router(static function (Routes $r): void {
            $group = $r->group('/api');
            $group->get('', 'root');
            $group->get('/', 'root-slash');
        });

        static::assertSame('root', self::find($router, 'GET', '/api')['handler'] ?? null);
        static::assertSame('root-slash', self::find($router, 'GET', '/api/')['handler'] ?? null);
    }

    public function testSiblingGroupsWithTheSamePrefixKeepTheirOwnMiddleware(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->group('/api')->middleware('public')->get('/status', 'status');
            $r->group('/api')->middleware('auth')->get('/me', 'me');
        });

        static::assertSame(['public'], self::find($router, 'GET', '/api/status')['middleware'] ?? null);
        static::assertSame(['auth'], self::find($router, 'GET', '/api/me')['middleware'] ?? null);
    }

    public function testTagsAreStoredInTheMetadata(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/login', 'login')->tag('public');
            $r->get('/health', 'health')->tag('public', 'internal')->tag('internal');
            $r->get('/me', 'me');
        });

        static::assertSame(['public'], self::find($router, 'GET', '/login')['tags'] ?? null);
        static::assertSame(['public', 'internal'], self::find($router, 'GET', '/health')['tags'] ?? null);
        static::assertArrayNotHasKey('tags', self::find($router, 'GET', '/me') ?? []);
    }

    public function testGroupTagsAreInheritedOutermostFirst(): void
    {
        $router = self::router(static function (Routes $r): void {
            $api = $r->group('/api')->tag('api');
            $api->group()->tag('public')->post('/login', 'login')->tag('rate-limited', 'api');
            $api->get('/me', 'me');
        });

        static::assertSame(
            ['api', 'public', 'rate-limited'],
            self::find($router, 'POST', '/api/login')['tags'] ?? null,
        );
        static::assertSame(['api'], self::find($router, 'GET', '/api/me')['tags'] ?? null);
    }

    public function testNameIsStoredInTheMetadata(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->group('/users')->get('/{id}', 'show')->name('users.show');
        });

        static::assertSame(
            [
                'handler' => 'show',
                'middleware' => [],
                'name' => 'users.show',
                'path' => '/users/{id}',
                'methods' => ['GET'],
            ],
            self::find($router, 'GET', '/users/1'),
        );
    }

    public function testFiltersAreStoredInTheMetadataAlongsideMethods(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/beta', 'beta')->filter(FeatureRouteFilter::class);
        });

        static::assertSame(
            [
                'handler' => 'beta',
                'middleware' => [],
                'methods' => ['GET'],
                'filters' => [FeatureRouteFilter::class],
            ],
            self::find($router, 'GET', '/beta'),
        );
    }

    public function testAHandlerMiddlewareOrFilterInstanceBecomesARegistryId(): void
    {
        $routes = new Routes(new Registry());
        $handler = new stdClass();
        $middleware = new stdClass();
        $filter = new FeatureRouteFilter('beta');
        $routes->get('/x', $handler)->middleware($middleware)->filter($filter);

        /** @var array<string, mixed> $metadata */
        $metadata = $routes->definitions()[0]->metadata;
        /** @var list<mixed> $middlewareIds */
        $middlewareIds = $metadata['middleware'];
        /** @var list<mixed> $filters */
        $filters = $metadata['filters'];

        static::assertIsInt($metadata['handler']);
        static::assertSame($handler, $routes->registry()->get($metadata['handler']));

        static::assertCount(1, $middlewareIds);
        static::assertIsInt($middlewareIds[0]);
        static::assertSame($middleware, $routes->registry()->get($middlewareIds[0]));

        static::assertCount(1, $filters);
        static::assertIsInt($filters[0]);
        static::assertSame($filter, $routes->registry()->get($filters[0]));
    }

    public function testAClassNameStaysLiteralEvenWithOtherInstancesAround(): void
    {
        $routes = new Routes(new Registry());
        $routes->get('/x', 'show')->middleware('api')->filter(FeatureRouteFilter::class);

        /** @var array<string, mixed> $metadata */
        $metadata = $routes->definitions()[0]->metadata;

        static::assertSame('show', $metadata['handler']);
        static::assertSame(['api'], $metadata['middleware']);
        static::assertSame([FeatureRouteFilter::class], $metadata['filters']);
    }

    public function testRootMiddlewareIsTheTableMetadataNotPartOfAnyRoute(): void
    {
        $routes = new Routes(new Registry());
        $routes->middleware(['log', 'cors']);
        $routes->get('/a', 'a');
        $routes->group('/api')->middleware('api')->get('/b', 'b');

        $router = new Router($routes->table());

        static::assertSame(['middleware' => ['log', 'cors']], $router->tableMetadata());
        static::assertSame([], self::find($router, 'GET', '/a')['middleware'] ?? null);
        static::assertSame(['api'], self::find($router, 'GET', '/api/b')['middleware'] ?? null);
    }

    public function testRootMiddlewareGivenAsAnInstanceIsARegistryIdInTheTableMetadata(): void
    {
        $routes = new Routes(new Registry());
        $middleware = new stdClass();
        $routes->middleware($middleware);

        /** @var array{middleware: list<mixed>} $metadata */
        $metadata = $routes->table()->metadata();

        static::assertIsInt($metadata['middleware'][0]);
        static::assertSame($middleware, $routes->registry()->get($metadata['middleware'][0]));
    }

    public function testRoutesKeepDeclarationOrderAcrossGroups(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/users', 'first');
            $r->group()->get('/users', 'second');
        });

        $result = $router->match('/users');

        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame('first', $result->route['handler'] ?? null);
    }

    public function testInvokableClassesWork(): void
    {
        $definitions = new class {
            public function __invoke(Routes $r): void
            {
                $r->get('/invokable', 'yes');
            }
        };
        $raw = new class {
            /** @return list<RouteDefinition> */
            public function __invoke(): array
            {
                return [new RouteDefinition('/raw', 'raw')];
            }
        };

        static::assertSame('yes', self::find(self::router($definitions), 'GET', '/invokable')['handler'] ?? null);
        $result = new Router(new CallableRouteTable($raw))->match('/raw');
        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame('raw', $result->route);
    }

    public function testDeclaringRoutesRunsImmediately(): void
    {
        $calls = new ArrayObject();
        self::router(static function (Routes $r) use ($calls): void {
            $calls->append(true);
            $r->get('/a', 'a');
        });

        // No match() needed: building Routes already ran the definition, unlike compiling, which
        // Router still only does once its matcher is first used (see RouterTest for that).
        static::assertCount(1, $calls);
    }

    /**
     * @return iterable<string, array{Closure(Routes): void, string}>
     */
    public static function invalidDeclarationProvider(): iterable
    {
        yield 'group prefix without leading slash' => [
            static fn(Routes $r) => $r->group('api'),
            'Group prefix "api" must start with "/"',
        ];
        yield 'group prefix with trailing slash' => [
            static fn(Routes $r) => $r->group('/api/'),
            'Group prefix "/api/" must start with "/" and must not end with "/"',
        ];
        yield 'empty path outside a prefixed group' => [
            static fn(Routes $r) => $r->group()->get('', 'x'),
            'Route path "" must start with "/"',
        ];
        yield 'invalid method' => [
            static fn(Routes $r) => $r->map(['GET', 'NO WAY'], '/x', 'x'),
            'invalid HTTP method "NO WAY"',
        ];
        yield 'no methods' => [
            static fn(Routes $r) => $r->map([], '/x', 'x'),
            'needs at least one HTTP method',
        ];
        yield 'duplicate name' => [
            static function (Routes $r): void {
                $r->get('/a', 'a')->name('home');
                $r->group('/b')->get('', 'b')->name('home');
            },
            'Route name "home" is used by both "/a" and "/b"',
        ];
        yield 'empty name' => [
            static fn(Routes $r) => $r->get('/a', 'a')->name(''),
            'cannot have an empty name',
        ];
        yield 'empty route tag' => [
            static fn(Routes $r) => $r->get('/a', 'a')->tag('public', ''),
            'Route "/a" cannot have an empty tag',
        ];
        yield 'empty group tag' => [
            static fn(Routes $r) => $r->group('/api')->tag(''),
            'Group "/api" cannot have an empty tag',
        ];
        yield 'filter class that does not implement Filter' => [
            static fn(Routes $r) => $r->get('/a', 'a')->filter(stdClass::class),
            'uses filter "stdClass", which does not implement',
        ];
        yield 'integer handler' => [
            static fn(Routes $r) => $r->get('/a', 7),
            'Route "/a" handler cannot be an integer (7)',
        ];
        yield 'integer route middleware' => [
            static fn(Routes $r) => $r->get('/a', 'a')->middleware(['auth', 3]),
            'Route "/a" middleware cannot be an integer (3)',
        ];
        yield 'integer group middleware' => [
            static fn(Routes $r) => $r->group('/api')->middleware(0),
            'Group "/api" middleware cannot be an integer (0)',
        ];
        yield 'integer root middleware' => [
            static fn(Routes $r) => $r->middleware(1),
            'Routes without a prefix middleware cannot be an integer (1)',
        ];
    }

    /**
     * @param Closure(Routes): void $define
     */
    #[DataProvider('invalidDeclarationProvider')]
    public function testRejectsInvalidDeclarations(Closure $define, string $message): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, delimiter: '/') . '/');

        self::router($define)->matcher();
    }
}
