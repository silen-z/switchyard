<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SilenZ\Segmatch\Cache\RouteCache;
use SilenZ\Segmatch\Exception\UrlGenerationException;
use SilenZ\Segmatch\Http\Dispatcher;
use SilenZ\Segmatch\Http\Found;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\Http\UrlGenerator;
use SilenZ\Segmatch\Router;
use stdClass;

use function preg_quote;

final class UrlGeneratorTest extends TestCase
{
    private static function router(?RouteCache $cache = null): Router
    {
        return new Router(Routes::define(static function (Routes $r): void {
            $r->get('/', 'home')->name('home');
            $r->group('/api')->define(static function (Routes $r): void {
                $r->get('/users/{id}', 'show')->name('users.show');
                $r->get('/users/{id}/posts/{post}', 'post')->name('users.post');
                $r->get('/users', 'list');
            });
            $r->get('/assets/{path*}', 'assets')->name('assets');
            $r->get('/files/{path+}', 'files')->name('files');
            $r->get('/{page*}', 'frontend')->name('frontend');
        }), $cache);
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
        $router = self::router();
        $urls = new UrlGenerator($router);
        $dispatcher = new Dispatcher($router);

        foreach ([
            ['users.show', ['id' => 'a/b c?']],
            ['users.post', ['id' => '%', 'post' => 'é']],
            ['files', ['path' => 'docs/a b/c%.pdf']],
        ] as [$name, $params]) {
            $result = $dispatcher->dispatch('GET', $urls->url($name, $params));

            static::assertInstanceOf(Found::class, $result);
            static::assertSame($name, $result->name);
            static::assertSame($params, $result->params);
        }
    }

    public function testWorksFromTheCache(): void
    {
        $cache = new class implements RouteCache {
            /** @var array<string, array<array-key, mixed>> */
            public array $entries = [];

            public function get(string $key): ?array
            {
                return $this->entries[$key] ?? null;
            }

            public function set(string $key, array $compiled): void
            {
                $this->entries[$key] = $compiled;
            }
        };
        self::router($cache)->matcher();

        $cached = new Router(static fn(): never => throw new RuntimeException('Routes were declared.'), $cache);

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
