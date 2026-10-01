<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use ArrayObject;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Http\Guards;
use SilenZ\Segmatch\Http\MethodGuard;
use SilenZ\Segmatch\Http\PatternGuard;
use SilenZ\Segmatch\Http\RouteCollector;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;

use function preg_quote;

final class RoutesTest extends TestCase
{
    /**
     * @param callable(RouteCollector): void $define
     */
    private static function router(callable $define): Router
    {
        return new Router(Routes::define($define));
    }

    /**
     * Metadata of the route a request reaches, choosing between routes by HTTP method.
     *
     * @return array<string, mixed>|null
     */
    private static function find(Router $router, string $method, string $path): ?array
    {
        $result = $router->match($path, Guards::for($method));

        if (!$result instanceof RouteMatch) {
            return null;
        }

        /** @var array<string, mixed> */
        return $result->route;
    }

    public function testVerbHelpersDeclareRoutesPerMethod(): void
    {
        $router = self::router(static function (RouteCollector $r): void {
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
            ['handler' => 'list', 'middleware' => [], 'guards' => [MethodGuard::class => ['GET']]],
            self::find($router, 'GET', '/users'),
        );
        static::assertSame('create', self::find($router, 'POST', '/users')['handler'] ?? null);
        static::assertSame('replace', self::find($router, 'PUT', '/users/1')['handler'] ?? null);
        static::assertSame('update', self::find($router, 'PATCH', '/users/1')['handler'] ?? null);
        static::assertSame('delete', self::find($router, 'DELETE', '/users/1')['handler'] ?? null);
        static::assertSame('options', self::find($router, 'OPTIONS', '/users')['handler'] ?? null);
        static::assertSame(
            [MethodGuard::class => ['GET', 'HEAD']],
            self::find($router, 'HEAD', '/health')['guards'] ?? null,
        );
        static::assertSame(
            ['handler' => 'webhook', 'middleware' => []],
            self::find($router, 'PURGE', '/webhooks/github'),
        );
    }

    public function testGroupsPrefixPathsAndInheritMiddlewareOutermostFirst(): void
    {
        $router = self::router(static function (RouteCollector $r): void {
            $r
                ->group('/api')
                ->middleware('api')
                ->define(static function (RouteCollector $r): void {
                    $r
                        ->group()
                        ->middleware(['guest'])
                        ->define(static function (RouteCollector $r): void {
                            $r->post('/login', 'login');
                        });
                    $r
                        ->group()
                        ->middleware('auth')
                        ->define(static function (RouteCollector $r): void {
                            $r->get('/users/{id}', 'user');
                            $r
                                ->group('/admin')
                                ->middleware('admin')
                                ->define(static function (RouteCollector $r): void {
                                    $r->get('/stats', 'stats')->middleware(['audit', 'log']);
                                });
                        });
                });
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
        $router = self::router(static function (RouteCollector $r): void {
            $group = $r->group('/api');
            $group->define(static fn(RouteCollector $r) => $r->get('/a', 'a'));
            $group->middleware('late');
            $group->define(static fn(RouteCollector $r) => $r->get('/b', 'b'));
        });

        static::assertSame(['late'], self::find($router, 'GET', '/api/a')['middleware'] ?? null);
        static::assertSame(['late'], self::find($router, 'GET', '/api/b')['middleware'] ?? null);
    }

    public function testRouteOnTheGroupPrefixItself(): void
    {
        $router = self::router(static function (RouteCollector $r): void {
            $r->group('/api')->define(static function (RouteCollector $r): void {
                $r->get('', 'root');
                $r->get('/', 'root-slash');
            });
        });

        static::assertSame('root', self::find($router, 'GET', '/api')['handler'] ?? null);
        static::assertSame('root-slash', self::find($router, 'GET', '/api/')['handler'] ?? null);
    }

    public function testSiblingGroupsWithTheSamePrefixKeepTheirOwnMiddleware(): void
    {
        $router = self::router(static function (RouteCollector $r): void {
            $r
                ->group('/api')
                ->middleware('public')
                ->define(static fn(RouteCollector $r) => $r->get('/status', 'status'));
            $r
                ->group('/api')
                ->middleware('auth')
                ->define(static fn(RouteCollector $r) => $r->get('/me', 'me'));
        });

        static::assertSame(['public'], self::find($router, 'GET', '/api/status')['middleware'] ?? null);
        static::assertSame(['auth'], self::find($router, 'GET', '/api/me')['middleware'] ?? null);
    }

    public function testNameAndWhereAreStoredInTheMetadata(): void
    {
        $router = self::router(static function (RouteCollector $r): void {
            $r->group('/users')->define(static function (RouteCollector $r): void {
                $r->get('/{id}', 'show')->name('users.show')->where('id', '\d+');
            });
        });

        static::assertSame(
            [
                'handler' => 'show',
                'middleware' => [],
                'name' => 'users.show',
                'guards' => [MethodGuard::class => ['GET'], PatternGuard::class => ['id' => '\d+']],
            ],
            self::find($router, 'GET', '/users/1'),
        );
    }

    public function testRoutesKeepDeclarationOrderAcrossGroups(): void
    {
        $router = self::router(static function (RouteCollector $r): void {
            $r->get('/users', 'first');
            $r->group()->define(static fn(RouteCollector $r) => $r->get('/users', 'second'));
        });

        $result = $router->match('/users');

        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame('first', $result->route['handler'] ?? null);
    }

    public function testInvokableClassesWork(): void
    {
        $definitions = new class {
            public function __invoke(RouteCollector $r): void
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
        $result = new Router($raw)->match('/raw');
        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame('raw', $result->route);
    }

    public function testRoutesAreOnlyDeclaredOnFirstUse(): void
    {
        $calls = new ArrayObject();
        $router = self::router(static function (RouteCollector $r) use ($calls): void {
            $calls->append(true);
            $r->get('/a', 'a');
        });

        static::assertCount(0, $calls);
        $router->match('/a');
        static::assertInstanceOf(NoMatch::class, $router->match('/b'));
        static::assertCount(1, $calls);
    }

    /**
     * @return iterable<string, array{Closure(RouteCollector): void, string}>
     */
    public static function invalidDeclarationProvider(): iterable
    {
        yield 'group prefix without leading slash' => [
            static fn(RouteCollector $r) => $r->group('api'),
            'Group prefix "api" must start with "/"',
        ];
        yield 'group prefix with trailing slash' => [
            static fn(RouteCollector $r) => $r->group('/api/'),
            'Group prefix "/api/" must start with "/" and must not end with "/"',
        ];
        yield 'empty path outside a prefixed group' => [
            static fn(RouteCollector $r) => $r->group()->define(static fn(RouteCollector $r) => $r->get('', 'x')),
            'Route path "" must start with "/"',
        ];
        yield 'invalid method' => [
            static fn(RouteCollector $r) => $r->map(['GET', 'NO WAY'], '/x', 'x'),
            'invalid HTTP method "NO WAY"',
        ];
        yield 'no methods' => [
            static fn(RouteCollector $r) => $r->map([], '/x', 'x'),
            'needs at least one HTTP method',
        ];
        yield 'duplicate name' => [
            static function (RouteCollector $r): void {
                $r->get('/a', 'a')->name('home');
                $r->group('/b')->define(static fn(RouteCollector $r) => $r->get('', 'b')->name('home'));
            },
            'Route name "home" is used by both "/a" and "/b"',
        ];
        yield 'empty name' => [
            static fn(RouteCollector $r) => $r->get('/a', 'a')->name(''),
            'cannot have an empty name',
        ];
        yield 'constraint on an unknown parameter' => [
            static fn(RouteCollector $r) => $r->get('/users/{id}', 'x')->where('slug', '\w+'),
            'Route "/users/{id}" constrains parameter "slug", which its path does not have',
        ];
        yield 'invalid constraint pattern' => [
            static fn(RouteCollector $r) => $r->get('/users/{id}', 'x')->where('id', '(\d+'),
            'invalid pattern "(\d+" for parameter "id"',
        ];
        yield 'closure as handler' => [
            static fn(RouteCollector $r) => $r->get('/x', static fn() => null),
            'Metadata of route "/x" contains a value of type Closure',
        ];
    }

    /**
     * @param Closure(RouteCollector): void $define
     */
    #[DataProvider('invalidDeclarationProvider')]
    public function testRejectsInvalidDeclarations(Closure $define, string $message): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, delimiter: '/') . '/');

        self::router($define)->matcher();
    }
}
