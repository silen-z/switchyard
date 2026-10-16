<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use SilenZ\Beeline\MetadataRegistry;
use SilenZ\Beeline\RouteDefinition;
use SilenZ\Beeline\RouteTable;

/**
 * A {@see RouteTable} whose {@see collect()} delegates to a plain closure — for a test that wants a
 * throwaway table without declaring a dedicated subclass for it, e.g. one bypassing
 * {@see \SilenZ\Switchyard\Routes}/{@see \SilenZ\Switchyard\LazyRoutes} entirely.
 */
final class CallableRouteTable extends RouteTable
{
    /** @var callable(array<int, RouteDefinition> &$definitions, mixed &$metadata): void */
    private readonly mixed $collect;

    /**
     * @param callable(array<int, RouteDefinition> &$definitions, mixed &$metadata): void $collect
     */
    public function __construct(
        callable $collect,
        ?string $cacheKey = null,
        private readonly ?MetadataRegistry $registry = null,
    ) {
        $this->collect = $collect;
        $this->cacheKey = $cacheKey;
    }

    /**
     * @param array<array-key, mixed> &$definitions always empty — see {@see RouteTable::collect()}
     * @param-out array<int, RouteDefinition> $definitions
     */
    protected function collect(array &$definitions): mixed
    {
        /** @var array<int, RouteDefinition> $typed always empty going in, so this is just a type assertion */
        $typed = $definitions;
        $metadata = null;
        ($this->collect)($typed, $metadata);
        $definitions = $typed;

        return $metadata;
    }

    public function registry(): ?MetadataRegistry
    {
        return $this->registry;
    }
}
