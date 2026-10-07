<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilenZ\Beeline\Cache\RouteCache;
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\Exception\UrlGenerationException;
use SilenZ\Switchyard\Handler;
use SilenZ\Switchyard\Routes;
use SilenZ\Switchyard\Tests\Fixtures\ArrayRouteCache;
use SilenZ\Switchyard\Tests\Fixtures\EchoContainer;
use SilenZ\Switchyard\UrlGenerator;
use stdClass;

use function json_decode;
use function preg_quote;

use const JSON_THROW_ON_ERROR;

final class UrlGeneratorTest extends TestCase
{
    private static function declare(Routes $routes): void
    {
        $routes->get('/', 'home')->name('home');

        $api = $routes->group('/api');
        $api->get('/users/{id}', 'show')->name('users.show');
        $api->get('/users/{id}/posts/{post}', 'post')->name('users.post');
        $api->get('/users', 'list');

        $routes->get('/assets/{path*}', 'assets')->name('assets');
        $routes->get('/files/{path+}', 'files')->name('files');
        $routes->get('/{page*}', 'frontend')->name('frontend');
    }

    private static function router(?RouteCache $cache = null, ?string $cacheKey = null): Router
    {
        $routes = new Routes();
        self::declare($routes);

        return new Router($routes->table($cacheKey), $cache);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function urls(): iterable
    {
        yield 'static' => ['home', [], '/'];
        yield 'parameter' => ['users.show', ['id' => 42], '/api/users/42'];
        yield 'several parameters' => ['users.post', ['post' => 'b', 'id' => 'a'], '/api/users/a/posts/b'];
        yield 'extra parameters become the query' => [
            'users.show',
            ['id' => 42, 'tab' => 'x y', 'f' => ['a' => 1]],
            '/api/users/42?tab=x%20y&f%5Ba%5D=1',
        ];
        yield 'null query values are left out' => ['users.show', ['id' => 1, 'tab' => null], '/api/users/1'];
        yield 'parameters are encoded' => ['users.show', ['id' => 'a/b c?'], '/api/users/a%2Fb%20c%3F'];
        yield 'catch-alls keep their slashes' => ['files', ['path' => 'docs/a b.pdf'], '/files/docs/a%20b.pdf'];
        yield 'empty zero-or-more catch-all' => ['assets', ['path' => ''], '/assets'];
        yield 'empty catch-all at the root' => ['frontend', ['page' => ''], '/'];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('urls')]
    public function testGeneratesUrls(string $name, array $params, string $url): void
    {
        static::assertSame($url, new UrlGenerator(self::router())->url($name, $params));
    }

    public function testGeneratedUrlsMatchTheirRoute(): void
    {
        $urls = new UrlGenerator(self::router());

        $routes = new Routes();
        self::declare($routes);
        $builder = new Handler(new EchoContainer(), new Router($routes->table()));

        foreach ([
            ['users.show', ['id' => 'a/b c?']],
            ['users.post', ['id' => '%', 'post' => 'é']],
            ['files', ['path' => 'docs/a b/c%.pdf']],
        ] as [$name, $params]) {
            $request = new ServerRequest('GET', $urls->url($name, $params));
            // EchoHandler answers with the route's Found as JSON.
            $found = json_decode(
                (string) $builder->build($request)->handle($request)->getBody(),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );

            static::assertIsArray($found);
            static::assertSame($name, $found['name']);
            static::assertSame($params, $found['params']);
        }
    }

    public function testWorksFromTheCache(): void
    {
        $cache = new ArrayRouteCache();
        self::router($cache, 'routes')->metadata();

        // Eager routes always re-declare, cache or not — what the cache actually buys is skipping
        // Compiler::compile(), not the declaration itself; a fresh Routes/MetadataRegistry pair,
        // declared the same way, resolves the cached tree's metadata ids right back to their names.
        $cached = self::router($cache, 'routes');

        static::assertSame('/api/users/42', new UrlGenerator($cached)->url('users.show', ['id' => 42]));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'unknown route' => ['nope', [], 'No route is named "nope".'];
        yield 'unnamed routes are unknown' => ['list', [], 'No route is named "list".'];
        yield 'missing parameter' => [
            'users.show',
            [],
            'Cannot generate a URL for route "users.show": missing parameter "id".',
        ];
        yield 'missing catch-all' => [
            'assets',
            [],
            'Cannot generate a URL for route "assets": missing parameter "path".',
        ];
        yield 'empty parameter' => [
            'users.show',
            ['id' => ''],
            'Cannot generate a URL for route "users.show": parameter "id" must not be empty.',
        ];
        yield 'empty one-or-more catch-all' => [
            'files',
            ['path' => ''],
            'Cannot generate a URL for route "files": parameter "path" must not be empty.',
        ];
        yield 'unsupported value' => [
            'users.show',
            ['id' => new stdClass()],
            'Cannot generate a URL for route "users.show": parameter "id" must be a string, int or Stringable, got stdClass.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('invalid')]
    public function testRejectsInvalidRequests(string $name, array $params, string $message): void
    {
        $this->expectException(UrlGenerationException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/');

        new UrlGenerator(self::router())->url($name, $params);
    }
}
