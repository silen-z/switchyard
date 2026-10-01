<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Http\Guards;
use SilenZ\Segmatch\Http\MethodGuard;
use SilenZ\Segmatch\Http\Request;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\NoMatch;
use SilenZ\Segmatch\RouteDefinition;
use SilenZ\Segmatch\RouteMatch;
use SilenZ\Segmatch\Router;
use SilenZ\Segmatch\Tests\Http\Fixtures\FeatureGuard;
use SilenZ\Segmatch\Tests\Http\Fixtures\NumericGuard;
use stdClass;

use function preg_quote;

final class GuardsTest extends TestCase
{
    private static function router(): Router
    {
        return new Router(Routes::define(static function (Routes $r): void {
            $r->get('/users', 'list');
            $r->post('/users', 'create');
            $r->post('/users/new', 'create-form');
            $r->get('/users/{id}', 'show')->guard(NumericGuard::class, 'id');
            $r->get('/users/{slug}', 'by-slug');
            $r->map(['PUT', 'patch'], '/users/{id}', 'update')->guard(NumericGuard::class, 'id');
            $r->any('/webhooks/{provider}', 'webhook');
            $r->get('/beta/{page}', 'beta')->guard(FeatureGuard::class, 'beta');
            $r->get('/{path+}', 'frontend');
        }));
    }

    private static function handler(RouteMatch|NoMatch $result): mixed
    {
        if (!$result instanceof RouteMatch) {
            return null;
        }

        /** @var array{handler: mixed} $route */
        $route = $result->route;

        return $route['handler'];
    }

    public function testSelectsTheRouteForTheMethod(): void
    {
        $router = self::router();

        static::assertSame('list', self::handler($router->match('/users', Guards::for('GET'))));
        static::assertSame('create', self::handler($router->match('/users', Guards::for('post'))));
        static::assertSame('update', self::handler($router->match('/users/7', Guards::for('PATCH'))));
    }

    public function testFallsThroughToARouteThatAcceptsTheMethod(): void
    {
        $result = self::router()->match('/users/new', Guards::for('GET'));

        static::assertSame('by-slug', self::handler($result));
        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame(['slug' => 'new'], $result->params);
    }

    public function testPatternsChooseBetweenRoutesOfTheSameShape(): void
    {
        $router = self::router();

        static::assertSame('show', self::handler($router->match('/users/42', Guards::for('GET'))));
        static::assertSame('by-slug', self::handler($router->match('/users/john', Guards::for('GET'))));
    }

    public function testAnyAcceptsEveryMethod(): void
    {
        static::assertSame('webhook', self::handler(self::router()->match('/webhooks/github', Guards::for('PURGE'))));
    }

    public function testCustomGuardsSeeTheRequestAttributes(): void
    {
        $router = self::router();
        $enabled = new Request('GET', ['features' => ['beta' => true]]);

        static::assertSame('beta', self::handler($router->match('/beta/1', Guards::for($enabled))));
        // Switched off, the route doesn't exist and the request falls through to the catch-all.
        static::assertSame('frontend', self::handler($router->match('/beta/1', Guards::for('GET'))));
    }

    public function testAllowedMethodsComeFromRoutesRejectedOnlyForTheirMethod(): void
    {
        $router = self::router();
        $request = new Request('DELETE');

        $result = $router->match('/users', Guards::for($request));
        static::assertInstanceOf(NoMatch::class, $result);
        static::assertSame(['GET', 'POST'], Guards::allowedMethods($result, $request));

        // "/users/7": show and update match the pattern, by-slug matches too, the catch-all is GET.
        $result = $router->match('/users/7', Guards::for($request));
        static::assertInstanceOf(NoMatch::class, $result);
        static::assertSame(['GET', 'PUT', 'PATCH'], Guards::allowedMethods($result, $request));

        // "/users/john": update's numeric guard fails, so PUT and PATCH are not allowed here.
        $result = $router->match('/users/john', Guards::for($request));
        static::assertInstanceOf(NoMatch::class, $result);
        static::assertSame(['GET'], Guards::allowedMethods($result, $request));
    }

    public function testRouteRejectedForAnotherReasonDoesNotCountAsAllowed(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/beta', 'beta')->guard(FeatureGuard::class, 'beta');
        }));

        $result = $router->match('/beta', Guards::for('POST'));

        static::assertInstanceOf(NoMatch::class, $result);
        static::assertSame([], Guards::allowedMethods($result, 'POST'));
    }

    public function testRoutesWithoutGuardsAlwaysApply(): void
    {
        $router = new Router(static fn(): array => [new RouteDefinition('/raw', 'raw')]);

        $result = $router->match('/raw', Guards::for('DELETE'));

        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame('raw', $result->route);
    }

    public function testGuardsInTheMetadata(): void
    {
        $result = self::router()->match('/users/7', Guards::for('PUT'));

        static::assertInstanceOf(RouteMatch::class, $result);
        static::assertSame(
            [
                'handler' => 'update',
                'middleware' => [],
                'guards' => [
                    MethodGuard::class => ['PUT', 'PATCH'],
                    NumericGuard::class => 'id',
                ],
            ],
            $result->route,
        );
    }

    /**
     * @return iterable<string, array{Closure(Routes): void, string}>
     */
    public static function invalidGuardProvider(): iterable
    {
        yield 'class that is not a guard' => [
            static fn(Routes $r) => $r->get('/a', 'a')->guard(stdClass::class),
            'uses guard "stdClass", which does not implement',
        ];
        yield 'method guard added directly' => [
            static fn(Routes $r) => $r->get('/a', 'a')->guard(MethodGuard::class, ['GET']),
            'cannot add ' . MethodGuard::class . ' directly',
        ];
        yield 'same guard twice' => [
            static fn(Routes $r) => $r->get('/a', 'a')->guard(FeatureGuard::class, 'x')->guard(
                FeatureGuard::class,
                'y',
            ),
            'uses guard "' . FeatureGuard::class . '" twice',
        ];
        yield 'object as guard configuration' => [
            static fn(Routes $r) => $r->get('/a', 'a')->guard(FeatureGuard::class, new stdClass()),
            'Metadata of route "/a" contains a value of type stdClass',
        ];
    }

    /**
     * @param Closure(Routes): void $define
     */
    #[DataProvider('invalidGuardProvider')]
    public function testRejectsInvalidGuards(Closure $define, string $message): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, delimiter: '/') . '/');

        new Router(Routes::define($define))->matcher();
    }
}
