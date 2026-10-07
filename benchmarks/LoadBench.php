<?php

declare(strict_types=1);

namespace SilenZ\Benchmarks;

use FastRoute\RouteCollector as FastRouteCollector;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

use function FastRoute\cachedDispatcher;

/**
 * Warm start: turning an existing cache file into a ready matcher, i.e. the per-request cost in
 * production. Without OPcache this is dominated by parsing the file; with OPcache it is mostly the
 * cost of `require` returning the immutable array.
 */
#[Groups(['load'])]
#[BeforeMethods('setUp')]
#[ParamProviders('provideFixtures')]
#[Revs(200)]
#[Iterations(5)]
#[Warmup(1)]
final class LoadBench
{
    private string $fixture = '';

    private string $fastRouteFile = '';

    /**
     * @param array{fixture: string} $params
     */
    public function setUp(array $params): void
    {
        $this->fixture = $params['fixture'];
        $this->fastRouteFile = Routers::writeCaches($params['fixture']);
    }

    public function benchFlat(): void
    {
        Routers::cachedFlat($this->fixture)->metadata();
    }

    public function benchFastRoute(): void
    {
        // The definition callback is never invoked while the cache file exists.
        cachedDispatcher(static function (FastRouteCollector $_collector): void {}, [
            'cacheFile' => $this->fastRouteFile,
        ]);
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public function provideFixtures(): iterable
    {
        return Fixtures::provideFixtures();
    }
}
