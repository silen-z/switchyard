<?php

declare(strict_types=1);

namespace SilenZ\Benchmarks;

use FastRoute\Dispatcher;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use SilenZ\Beeline\Matcher;

use function count;

/**
 * Steady-state matching over a varied request mix: each rev matches the next path of
 * {@see Fixtures::paths()}, so caches and branch predictors don't see the same request twice in a row.
 * The reported time is per single match.
 */
#[Groups(['match-mixed'])]
#[BeforeMethods('setUp')]
#[ParamProviders('provideFixtures')]
#[Revs(20_000)]
#[Iterations(10)]
#[Warmup(2)]
final class MixedMatchBench
{
    private Matcher $flat;

    private Dispatcher $fastRoute;

    /** @var non-empty-list<string> */
    private array $paths = [''];

    private int $count = 1;

    private int $next = 0;

    /**
     * @param array{fixture: string} $params
     */
    public function setUp(array $params): void
    {
        $routes = Fixtures::get($params['fixture'])['routes'];
        $this->flat = Routers::flat($routes);
        $this->fastRoute = Routers::fastRoute($routes);
        $this->paths = Fixtures::paths($params['fixture']);
        $this->count = count($this->paths);
        $this->next = 0;
    }

    public function benchFlat(): void
    {
        $this->flat->match($this->paths[$this->next++ % $this->count]);
    }

    public function benchFastRoute(): void
    {
        $this->fastRoute->dispatch('GET', $this->paths[$this->next++ % $this->count]);
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public function provideFixtures(): iterable
    {
        return Fixtures::provideFixtures();
    }
}
