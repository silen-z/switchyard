<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use UnitEnum;

use function count;
use function is_array;
use function is_scalar;

/**
 * Lets a handler, middleware entry or filter be a real instance or closure instead of only a class
 * name: a route's own metadata must stay plain data to survive the route cache ({@see Route}), so
 * anything that isn't already {@see wrap()}s it into here and gives back the id {@see
 * Instances::of()} resolves it from at request time instead.
 *
 * One `Registry` is shared by a {@see Routes} tree (the root and every `group()` nested under it,
 * {@see Route}s included), and rebuilt fresh every time the tree is declared — unlike the route cache,
 * which only turns declared routes into the compiled matching structure once. So the ids a cached
 * route's metadata carries only make sense together with the `Registry` of the same declaration code
 * that was running when the cache was built: changing what gets wrapped, or the order {@see wrap()} is
 * called in, needs a new cache key exactly like changing the routes themselves does.
 */
final class Registry
{
    /** @var list<mixed> */
    private array $values = [];

    /**
     * $value as-is if it's already cacheable route metadata (scalars, null, enums, or arrays of
     * those, recursively); otherwise stores it and returns the id to resolve it back from.
     */
    public function wrap(mixed $value): mixed
    {
        if (self::isPlain($value)) {
            return $value;
        }

        $this->values[] = $value;

        return count($this->values) - 1;
    }

    /**
     * @internal shared by {@see Instances::of()}
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
