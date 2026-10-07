<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Benchmarks;

use function array_key_exists;
use function crc32;
use function preg_replace;
use function usort;

/**
 * Route sets shared by all benchmarks. Each fixture is a list of route patterns in this router's
 * syntax plus named request paths exercising the interesting matching cases.
 *
 * @psalm-type Fixture = array{routes: list<string>, requests: array<string, string>}
 */
final class Fixtures
{
    public const array NAMES = ['small', 'medium', 'large', 'deep', 'branching', 'parameters'];

    /** @var array<string, Fixture> */
    private static array $loaded = [];

    /** @var array<string, list<string>> */
    private static array $paths = [];

    /**
     * @return Fixture
     */
    public static function get(string $name): array
    {
        if (!array_key_exists($name, self::$loaded)) {
            /** @var Fixture $fixture */
            $fixture = require __DIR__ . '/fixtures/' . $name . '.php';
            self::$loaded[$name] = $fixture;
        }

        return self::$loaded[$name];
    }

    /**
     * A varied request mix for the fixture: every route instantiated with concrete values, plus a
     * request below every fourth route that mostly ends in a 404 (or in a catch-all), in a fixed
     * pseudo-random order so consecutive requests hit unrelated parts of the tree.
     *
     * @return non-empty-list<string>
     */
    public static function paths(string $name): array
    {
        if (!array_key_exists($name, self::$paths)) {
            $paths = [];
            foreach (self::get($name)['routes'] as $index => $route) {
                $path = (string) preg_replace(
                    ['#\{\w+[*+]\}#', '#\{\w+\}#'],
                    ['files/app.css', (string) (100 + $index)],
                    $route,
                );
                $paths[] = $path;
                if (($index % 4) === 0) {
                    $paths[] = $path . '/missing';
                }
            }

            usort($paths, static fn(string $a, string $b): int => crc32($a) <=> crc32($b));
            self::$paths[$name] = $paths;
        }

        /** @var non-empty-list<string> */
        return self::$paths[$name];
    }

    /**
     * @return iterable<string, array{fixture: string}>
     */
    public static function provideFixtures(): iterable
    {
        foreach (self::NAMES as $name) {
            yield $name => ['fixture' => $name];
        }
    }

    /**
     * @return iterable<string, array{fixture: string, case: string, path: string}>
     */
    public static function provideRequests(): iterable
    {
        foreach (self::NAMES as $name) {
            foreach (self::get($name)['requests'] as $case => $path) {
                yield $name . '/' . $case => ['fixture' => $name, 'case' => $case, 'path' => $path];
            }
        }
    }

    /**
     * @return iterable<string, array{fixture: string, path: string}>
     */
    public static function provideMixedPaths(): iterable
    {
        foreach (self::NAMES as $name) {
            foreach (self::paths($name) as $path) {
                yield $name . ' ' . $path => ['fixture' => $name, 'path' => $path];
            }
        }
    }
}
