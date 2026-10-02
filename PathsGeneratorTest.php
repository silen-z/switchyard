<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\OpenApi;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\OpenApi\PathsGenerator;
use SilenZ\Segmatch\Router;

use function array_keys;

final class PathsGeneratorTest extends TestCase
{
    public function testStaticPathWithAPlaceholderResponse(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/ping', 'ping');
        }));

        static::assertSame(
            ['paths' => ['/ping' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]]],
            PathsGenerator::generate($router->definitions()),
        );
    }

    public function testPathParametersComeFromTheRouteSegments(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/users/{id}', 'show');
        }));

        $paths = PathsGenerator::generate($router->definitions())['paths'];

        static::assertSame(
            [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
            $paths['/users/{id}']['get']['parameters'],
        );
    }

    public function testCatchAllZeroOrMoreIsNotRequired(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/assets/{path*}', 'assets');
        }));

        $paths = PathsGenerator::generate($router->definitions())['paths'];

        static::assertSame(
            [['name' => 'path', 'in' => 'path', 'required' => false, 'schema' => ['type' => 'string']]],
            $paths['/assets/{path}']['get']['parameters'],
        );
    }

    public function testCatchAllOneOrMoreIsRequired(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/files/{path+}', 'files');
        }));

        $paths = PathsGenerator::generate($router->definitions())['paths'];

        static::assertTrue($paths['/files/{path}']['get']['parameters'][0]['required']);
    }

    public function testNameBecomesTheOperationId(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/users/{id}', 'show')->name('users.show');
        }));

        $paths = PathsGenerator::generate($router->definitions())['paths'];

        static::assertSame('users.show', $paths['/users/{id}']['get']['operationId']);
    }

    public function testUnnamedRouteHasNoOperationId(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/ping', 'ping');
        }));

        $paths = PathsGenerator::generate($router->definitions())['paths'];

        static::assertArrayNotHasKey('operationId', $paths['/ping']['get']);
    }

    public function testTagsAreCarriedOver(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/users', 'list')->tag('public', 'users');
        }));

        $paths = PathsGenerator::generate($router->definitions())['paths'];

        static::assertSame(['public', 'users'], $paths['/users']['get']['tags']);
    }

    public function testRoutesSharingAPathMergeIntoOnePathItem(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/users', 'list');
            $r->post('/users', 'create');
        }));

        $path = PathsGenerator::generate($router->definitions())['paths']['/users'];

        static::assertSame(['get', 'post'], array_keys($path));
    }

    public function testMapListsEachOfItsMethods(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->map(['PUT', 'PATCH'], '/users/{id}', 'update');
        }));

        $path = PathsGenerator::generate($router->definitions())['paths']['/users/{id}'];

        static::assertSame(['put', 'patch'], array_keys($path));
    }

    public function testAnyRouteListsEveryMethod(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->any('/webhooks/{provider}', 'webhook');
        }));

        $path = PathsGenerator::generate($router->definitions())['paths']['/webhooks/{provider}'];

        static::assertSame(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'], array_keys($path));
    }

    public function testTrailingSlashIsPreservedInThePath(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->get('/users/', 'trailing');
        }));

        static::assertArrayHasKey('/users/', PathsGenerator::generate($router->definitions())['paths']);
    }

    public function testGroupPrefixesAreIncludedInThePath(): void
    {
        $router = new Router(Routes::define(static function (Routes $r): void {
            $r->group('/api')->define(static function (Routes $r): void {
                $r->get('/users/{id}', 'show');
            });
        }));

        $paths = PathsGenerator::generate($router->definitions())['paths'];

        static::assertArrayHasKey('/api/users/{id}', $paths);
    }
}
