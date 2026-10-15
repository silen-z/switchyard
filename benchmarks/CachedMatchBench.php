<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Benchmarks;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector as FastRouteCollector;
use LogicException;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use SilenZ\Beeline\Cache\FileCache;
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\LazyRoutes;
use SilenZ\Switchyard\Routes;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection;

use function array_keys;
use function count;
use function FastRoute\cachedDispatcher;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function preg_match;
use function preg_replace;
use function str_contains;
use function unlink;
use function usort;

/**
 * Warm-cache steady state: one simulated request per rev — build the router from an already
 * written cache, then match one path, rotating through {@see Fixtures::paths()}. `Routes` still
 * declares on every request (its registry can't be cached); `LazyRoutes` and the third-party
 * routers only load their cache.
 */
#[Groups(['cached'])]
#[BeforeMethods('setUp')]
#[ParamProviders('provideFixtures')]
#[Revs(1000)]
#[Iterations(10)]
#[Warmup(1000)]
final class CachedMatchBench
{
    private const string DIR = __DIR__ . '/../var/cached-match-bench';

    private string $fixture = '';

    /** @var list<string> */
    private array $routes = [];

    /** @var list<string> */
    private array $paths = [];

    private int $i = 0;

    /**
     * @param array{fixture: string} $params
     */
    public function setUp(array $params): void
    {
        $this->fixture = $params['fixture'];
        $this->routes = Fixtures::get($this->fixture)['routes'];
        $this->paths = Fixtures::paths($this->fixture);
        $this->i = 0;

        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, recursive: true);
        }
        foreach (glob(self::DIR . '/*') ?: [] as $file) {
            unlink($file);
        }

        new Router($this->declareRoutes()->table('routes-' . $this->fixture), new FileCache(self::DIR))->match('/');
        new Router(LazyRoutes::table(function (LazyRoutes $r): void {
            foreach ($this->routes as $route) {
                $r->get($route, $route);
            }
        }, 'lazy-' . $this->fixture), new FileCache(self::DIR))->match('/');

        $collection = new RouteCollection();
        foreach (self::ordered($this->routes) as $index => $route) {
            $symfony = new SymfonyRoute((string) preg_replace('#\{(\w+)[*+]\}$#', '{$1}', $route), methods: ['GET']);
            if (preg_match('#\{(\w+)([*+])\}$#', $route, $m) === 1) {
                $symfony->setRequirement($m[1], $m[2] === '+' ? '.+' : '.*');
            }
            $collection->add('r' . $index, $symfony);
        }
        file_put_contents(self::symfonyFile($this->fixture), new CompiledUrlMatcherDumper($collection)->dump());

        $routes = $this->routes;
        cachedDispatcher(static function (FastRouteCollector $c) use ($routes): void {
            foreach (self::ordered($routes) as $index => $route) {
                $c->addRoute('GET', (string) preg_replace(
                    ['#/\{(\w+)\*\}$#', '#\{(\w+)\+\}$#'],
                    ['[/{$1:.*}]', '{$1:.+}'],
                    $route,
                ), $index);
            }
        }, ['cacheFile' => self::fastRouteFile($this->fixture)]);
    }

    public function benchRoutes(): void
    {
        new Router($this->declareRoutes()->table('routes-' . $this->fixture), new FileCache(self::DIR))->match(
            $this->next(),
        );
    }

    public function benchLazyRoutes(): void
    {
        new Router(
            LazyRoutes::table(static fn() => throw new LogicException('Cache miss.'), 'lazy-' . $this->fixture),
            new FileCache(self::DIR),
        )->match($this->next());
    }

    public function benchSymfony(): void
    {
        $matcher = new CompiledUrlMatcher(require self::symfonyFile($this->fixture), new RequestContext());
        try {
            $matcher->match($this->next());
        } catch (ResourceNotFoundException) {
        }
    }

    public function benchFastRoute(): void
    {
        cachedDispatcher(static fn() => throw new LogicException('Cache miss.'), [
            'cacheFile' => self::fastRouteFile($this->fixture),
        ])->dispatch('GET', $this->next());
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public function provideFixtures(): iterable
    {
        return Fixtures::provideFixtures();
    }

    private function next(): string
    {
        return $this->paths[$this->i++ % count($this->paths)];
    }

    private function declareRoutes(): Routes
    {
        $routes = new Routes();
        foreach ($this->routes as $route) {
            $routes->get($route, $route);
        }

        return $routes;
    }

    private static function symfonyFile(string $fixture): string
    {
        return self::DIR . '/' . $fixture . '.symfony.php';
    }

    private static function fastRouteFile(string $fixture): string
    {
        return self::DIR . '/' . $fixture . '.fast-route.php';
    }

    /**
     * Static routes, then parameter routes, then catch-alls: the precedence Beeline has, which
     * order-sensitive routers only get by registration order.
     *
     * @param list<string> $routes
     *
     * @return array<int, string>
     */
    private static function ordered(array $routes): array
    {
        $rank = static fn(string $r): int => match (true) {
            preg_match('#\{\w+[*+]\}$#', $r) === 1 => 2,
            str_contains($r, '{') => 1,
            default => 0,
        };
        $indexes = array_keys($routes);
        usort($indexes, static fn(int $a, int $b): int => [$rank($routes[$a]), $a] <=> [$rank($routes[$b]), $b]);
        $ordered = [];
        foreach ($indexes as $index) {
            $ordered[$index] = $routes[$index];
        }

        return $ordered;
    }
}
