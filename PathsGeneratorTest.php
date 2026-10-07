<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\OpenApi;

use OpenApi\Attributes as OA;
use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\OpenApi\PathsGenerator;
use SilenZ\Segmatch\Router;

final class PathsGeneratorTest extends TestCase
{
    /**
     * @param callable(Routes): void $define
     */
    private static function router(callable $define): Router
    {
        $routes = new Routes();
        $define($routes);

        return new Router($routes->table());
    }

    /**
     * @param list<OA\PathItem> $pathItems
     */
    private static function pathItem(array $pathItems, string $path): OA\PathItem
    {
        foreach ($pathItems as $pathItem) {
            if ($pathItem->path === $path) {
                return $pathItem;
            }
        }

        static::fail("No path item generated for \"{$path}\"");
    }

    public function testStaticPathWithAPlaceholderResponse(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/ping', 'ping');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());

        static::assertCount(1, $pathItems);
        $get = self::pathItem($pathItems, '/ping')->get;
        static::assertInstanceOf(OA\Get::class, $get);
        static::assertCount(1, $get->responses);
        static::assertSame(200, $get->responses[0]->response);
        static::assertSame('OK', $get->responses[0]->description);
    }

    public function testPathParametersComeFromTheRouteSegments(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/users/{id}', 'show');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());
        $get = self::pathItem($pathItems, '/users/{id}')->get;

        static::assertCount(1, $get->parameters);
        $parameter = $get->parameters[0];
        static::assertSame('id', $parameter->name);
        static::assertSame('path', $parameter->in);
        static::assertTrue($parameter->required);
        static::assertSame('string', $parameter->schema->type);
    }

    public function testCatchAllZeroOrMoreIsNotRequired(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/assets/{path*}', 'assets');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());
        $get = self::pathItem($pathItems, '/assets/{path}')->get;

        static::assertFalse($get->parameters[0]->required);
    }

    public function testCatchAllOneOrMoreIsRequired(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/files/{path+}', 'files');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());
        $get = self::pathItem($pathItems, '/files/{path}')->get;

        static::assertTrue($get->parameters[0]->required);
    }

    public function testNameBecomesTheOperationId(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/users/{id}', 'show')->name('users.show');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());

        static::assertSame('users.show', self::pathItem($pathItems, '/users/{id}')->get->operationId);
    }

    public function testUnnamedRouteHasNoOperationId(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/ping', 'ping');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());

        static::assertTrue(\OpenApi\Undefined::isDefault(self::pathItem($pathItems, '/ping')->get->operationId));
    }

    public function testTagsAreCarriedOver(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/users', 'list')->tag('public', 'users');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());

        static::assertSame(['public', 'users'], self::pathItem($pathItems, '/users')->get->tags);
    }

    public function testRoutesSharingAPathMergeIntoOnePathItem(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/users', 'list');
            $r->post('/users', 'create');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());

        static::assertCount(1, $pathItems);
        $pathItem = self::pathItem($pathItems, '/users');
        static::assertInstanceOf(OA\Get::class, $pathItem->get);
        static::assertInstanceOf(OA\Post::class, $pathItem->post);
    }

    public function testMapListsEachOfItsMethods(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->map(['PUT', 'PATCH'], '/users/{id}', 'update');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());
        $pathItem = self::pathItem($pathItems, '/users/{id}');

        static::assertInstanceOf(OA\Put::class, $pathItem->put);
        static::assertInstanceOf(OA\Patch::class, $pathItem->patch);
        static::assertTrue(\OpenApi\Undefined::isDefault($pathItem->get));
    }

    public function testAnyRouteListsEveryMethod(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->any('/webhooks/{provider}', 'webhook');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());
        $pathItem = self::pathItem($pathItems, '/webhooks/{provider}');

        static::assertInstanceOf(OA\Get::class, $pathItem->get);
        static::assertInstanceOf(OA\Put::class, $pathItem->put);
        static::assertInstanceOf(OA\Post::class, $pathItem->post);
        static::assertInstanceOf(OA\Delete::class, $pathItem->delete);
        static::assertInstanceOf(OA\Options::class, $pathItem->options);
        static::assertInstanceOf(OA\Head::class, $pathItem->head);
        static::assertInstanceOf(OA\Patch::class, $pathItem->patch);
        static::assertInstanceOf(OA\Trace::class, $pathItem->trace);
    }

    public function testTrailingSlashIsPreservedInThePath(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->get('/users/', 'trailing');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());

        static::assertInstanceOf(OA\Get::class, self::pathItem($pathItems, '/users/')->get);
    }

    public function testGroupPrefixesAreIncludedInThePath(): void
    {
        $router = self::router(static function (Routes $r): void {
            $r->group('/api')->get('/users/{id}', 'show');
        });

        $pathItems = PathsGenerator::generate($router->table()->definitions());

        static::assertInstanceOf(OA\Get::class, self::pathItem($pathItems, '/api/users/{id}')->get);
    }
}
