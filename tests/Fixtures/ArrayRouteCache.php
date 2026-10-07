<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use SilenZ\Beeline\Cache\RouteCache;

use function array_keys;

/**
 * A trivial in-memory {@see RouteCache}, standing in for a file or PSR-6 cache, so a test can see
 * what a router stored and hand the same entries to another one.
 */
final class ArrayRouteCache implements RouteCache
{
    /** @var array<string, array<array-key, mixed>> */
    private array $entries = [];

    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, array $compiled): void
    {
        $this->entries[$key] = $compiled;
    }

    /**
     * The keys stored so far, in insertion order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->entries);
    }
}
