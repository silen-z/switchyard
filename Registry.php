<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;
use UnitEnum;

use function count;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_scalar;
use function sprintf;

/**
 * Lets a handler, middleware entry or filter be a real instance or closure instead of only a class
 * name: a route's own metadata must stay plain data to survive the route cache ({@see Route}), so
 * anything that isn't already plain data is wrapped by {@see wrap()} and gives back an id {@see
 * get()} resolves it from at request time instead.
 *
 * One `Registry` is shared by a {@see Routes} tree (the root and every `group()` nested under it,
 * {@see Route}s included), and rebuilt fresh every time the tree is declared — unlike the route cache,
 * which only turns declared routes into the compiled matching structure once. So the ids a cached
 * route's metadata carries only make sense together with the `Registry` of the same declaration code
 * that was running when the cache was built: changing what gets wrapped, or the order {@see wrap()} is
 * called in, needs a new cache key exactly like changing the routes themselves does.
 *
 * Those ids are integers, so an integer is never accepted as a handler, middleware entry or filter of
 * its own: {@see RoutesHandlerBuilder} could not tell it apart from an id. Integers nested inside an
 * array are plain data like any other.
 *
 * A {@see strict()} registry wraps nothing: it's for routes declared lazily, only when the route cache
 * has no entry, where an instance given while declaring would no longer exist on the requests answered
 * from the cache. It lets plain data through unchanged and rejects everything else, so a lazily
 * declared tree's metadata never carries an id.
 */
final class Registry
{
    /** @var list<mixed> */
    private array $values = [];

    private bool $strict = false;

    /**
     * A registry that only accepts plain data, for routes declared lazily.
     */
    public static function strict(): self
    {
        $registry = new self();
        $registry->strict = true;

        return $registry;
    }

    /**
     * $value as-is if it's already cacheable route metadata (scalars, null, enums, or arrays of
     * those, recursively); otherwise stores it and returns the id to resolve it back from.
     *
     * @param string $owner what $value is, for the error message, e.g. `Route "/users" handler`
     *
     * @throws InvalidRouteException when $value is an integer, which would read as an id, or, for a
     *                               {@see strict()} registry, isn't plain data
     */
    public function wrap(mixed $value, string $owner): mixed
    {
        if (is_int($value)) {
            throw new InvalidRouteException(sprintf(
                '%s cannot be an integer (%d): integers are reserved for the ids of wrapped instances. '
                . 'Use a class name or a container identifier%s.',
                $owner,
                $value,
                $this->strict ? '' : ', or an instance',
            ));
        }

        if (self::isPlain($value)) {
            return $value;
        }

        if ($this->strict) {
            throw new InvalidRouteException(sprintf(
                '%s must be a class name or a container identifier, not %s: lazily declared routes are '
                . 'only declared when their cache is built, so an instance would not exist on the requests '
                . 'answered from it. Register it in the container, or declare these routes eagerly.',
                $owner,
                get_debug_type($value),
            ));
        }

        $this->values[] = $value;

        return count($this->values) - 1;
    }

    /**
     * @internal shared with {@see RoutesHandlerBuilder::instantiate()}
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
