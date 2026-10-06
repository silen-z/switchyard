<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use UnitEnum;

use function count;
use function is_array;
use function is_int;
use function is_scalar;
use function sprintf;

/**
 * Lets a handler, middleware entry or filter be a real instance or closure instead of only a class
 * name: a route's own metadata must stay plain data to survive the route cache, so anything that
 * isn't already is wrapped by {@see wrap()} into an id {@see get()} resolves it back from.
 *
 * One `Registry` is shared by a {@see Routes} tree — the root and every nested `group()` — and
 * rebuilt fresh every time the tree is declared; see {@see Routes::registry()} for what that means
 * for pairing it with a `Router`. {@see LazyRoutes} uses none at all: nothing it declares is ever
 * wrapped, since an instance given while declaring would no longer exist on the requests answered
 * from a cache hit — {@see LazyRoute} rejects one outright instead.
 */
final class Registry
{
    /** @var list<mixed> */
    private array $values = [];

    /**
     * $value as-is if it's already cacheable route metadata (scalars, null, enums, or arrays of
     * those, recursively); otherwise stores it and returns the id to resolve it back from. Since
     * those ids are integers, an integer is never accepted as a handler, middleware entry or filter
     * of its own — nested inside an array, it's plain data like any other — and since the id
     * assigned depends on the order values are wrapped in, a cache key must change whenever that
     * order, or what gets wrapped, would.
     *
     * @param string $owner what $value is, for the error message, e.g. `Route "/users" handler`
     *
     * @throws InvalidRouteException when $value is an integer, which would read as an id
     */
    public function wrap(mixed $value, string $owner): mixed
    {
        if (is_int($value)) {
            throw new InvalidRouteException(sprintf(
                '%s cannot be an integer (%d): integers are reserved for the ids of wrapped instances. '
                . 'Use a class name, a container identifier, or an instance.',
                $owner,
                $value,
            ));
        }

        if (self::isPlain($value)) {
            return $value;
        }

        $this->values[] = $value;

        return count($this->values) - 1;
    }

    /**
     * @internal shared with {@see Resolver}
     */
    public function get(int $id): mixed
    {
        return $this->values[$id];
    }

    private static function isPlain(mixed $value): bool
    {
        if ($value === null || is_scalar($value) || $value instanceof UnitEnum) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        // A plain array's items are arbitrary user data, so they're mixed by definition.
        // @mago-expect analysis:mixed-assignment
        foreach ($value as $item) {
            if (!self::isPlain($item)) {
                return false;
            }
        }

        return true;
    }
}
