<?php

declare(strict_types=1);

namespace SilenZ\Benchmarks;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector as FastRouteCollector;
use LogicException;
use SilenZ\Beeline\Cache\FileCache;
use SilenZ\Beeline\Compiler;
use SilenZ\Beeline\Matcher;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\Router;
use SilenZ\Beeline\RouteTable;

use function FastRoute\cachedDispatcher;
use function FastRoute\simpleDispatcher;
use function is_file;
use function preg_match;
use function preg_replace;
use function str_contains;
use function unlink;
use function usort;

/**
 * Builds equivalent routers from one fixture. Route metadata is the route's index in the fixture,
 * so results of both routers can be compared.
 */
final class Routers
{
    /**
     * @param list<string> $routes
     *
     * @return list<RouteDefinition>
     */
    public static function flatRoutes(array $routes): array
    {
        $definitions = [];
        foreach ($routes as $index => $route) {
            $definitions[] = new RouteDefinition($route, $index);
        }

        return $definitions;
    }

    /**
     * @param list<string> $routes
     */
    public static function flat(array $routes): Matcher
    {
        return new Matcher(Compiler::compile(new RouteTable(self::flatRoutes($routes))));
    }

    public const string CACHE_DIRECTORY = __DIR__ . '/../var/bench-cache';

    /**
     * Writes fresh caches of both routers for a fixture. This router's cache is stored in
     * {@see CACHE_DIRECTORY} under the fixture name as key.
     *
     * @return string the path of FastRoute's cache file
     */
    public static function writeCaches(string $fixture): string
    {
        $routes = Fixtures::get($fixture)['routes'];
        $fastRouteFile = self::CACHE_DIRECTORY . '/' . $fixture . '.fast-route.php';

        new FileCache(self::CACHE_DIRECTORY)->set(
            $fixture,
            Compiler::compile(new RouteTable(self::flatRoutes($routes))),
        );

        // FastRoute only writes its cache when the file does not exist yet.
        if (is_file($fastRouteFile)) {
            unlink($fastRouteFile);
        }
        self::fastRoute($routes, $fastRouteFile);

        return $fastRouteFile;
    }

    /**
     * This router as an application uses it, for a fixture whose cache was written by
     * {@see writeCaches()}. Declaring the routes would mean the cache was not used, so it fails.
     */
    public static function cachedFlat(string $fixture): Router
    {
        return new Router(
            new RouteTable(static fn(): iterable => throw new LogicException('Cache entry missing.'), $fixture),
            new FileCache(self::CACHE_DIRECTORY),
        );
    }

    /**
     * @param list<string> $routes
     */
    public static function fastRoute(array $routes, ?string $cacheFile = null): Dispatcher
    {
        $define = static function (FastRouteCollector $collector) use ($routes): void {
            foreach (self::fastRouteOrder($routes) as $index => $route) {
                $collector->addRoute('GET', self::toFastRoutePattern($route), $index);
            }
        };

        if ($cacheFile === null) {
            return simpleDispatcher($define);
        }

        return cachedDispatcher($define, ['cacheFile' => $cacheFile]);
    }

    /**
     * Translates `{name*}` / `{name+}` catch-alls into FastRoute's regex placeholders.
     */
    public static function toFastRoutePattern(string $route): string
    {
        return (string) preg_replace(['#/\{(\w+)\*\}$#', '#\{(\w+)\+\}$#'], ['[/{$1:.*}]', '{$1:.+}'], $route);
    }

    /**
     * FastRoute resolves overlaps by registration order and rejects static routes registered after a
     * variable route that shadows them. Registering static routes first, then parameter routes, then
     * catch-alls gives it the same static > parameter > catch-all precedence this router has.
     *
     * @param list<string> $routes
     *
     * @return array<int, string> original index => pattern
     */
    private static function fastRouteOrder(array $routes): array
    {
        $rank = static fn(string $route): int => match (true) {
            preg_match('#\{\w+[*+]\}$#', $route) === 1 => 2,
            str_contains($route, '{') => 1,
            default => 0,
        };

        $indexes = [];
        foreach ($routes as $index => $route) {
            $indexes[] = $index;
        }

        usort($indexes, static fn(int $a, int $b): int => [$rank($routes[$a]), $a] <=> [$rank($routes[$b]), $b]);

        $ordered = [];
        foreach ($indexes as $index) {
            $ordered[$index] = $routes[$index];
        }

        return $ordered;
    }
}
