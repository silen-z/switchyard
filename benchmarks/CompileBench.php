<?php

declare(strict_types=1);

namespace SilenZ\Benchmarks;

use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Cold build: route declarations to a ready-to-match structure, without any cache.
 */
#[Groups(['compile'])]
#[ParamProviders('provideFixtures')]
#[Revs(20)]
#[Iterations(5)]
#[Warmup(1)]
final class CompileBench
{
    /**
     * @param array{fixture: string} $params
     */
    public function benchFlat(array $params): void
    {
        Routers::flat(Fixtures::get($params['fixture'])['routes']);
    }

    /**
     * @param array{fixture: string} $params
     */
    public function benchFastRoute(array $params): void
    {
        Routers::fastRoute(Fixtures::get($params['fixture'])['routes']);
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public function provideFixtures(): iterable
    {
        return Fixtures::provideFixtures();
    }
}
