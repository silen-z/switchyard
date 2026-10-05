<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Container\ContainerInterface;
use UnexpectedValueException;

use function get_debug_type;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Turns what a route's metadata names — a handler, a middleware entry, a filter — into the real thing:
 * a {@see Registry} id into the instance or closure it stands for, a class name or container
 * identifier into what the container resolves it to.
 *
 * `$registry` must be the one the routes were declared with, which is why only
 * {@see RoutesHandlerBuilder} builds one, from the tree it owns.
 *
 * @internal
 */
final readonly class Resolver
{
    public function __construct(
        private Registry $registry,
        private ContainerInterface $container,
    ) {}

    /**
     * One entry as the real thing to run: a {@see Registry} id standing in for an instance or closure
     * the routes were declared with, a class name or container identifier for the container to
     * resolve, or anything else as itself.
     */
    public function entry(mixed $entry): mixed
    {
        if (is_int($entry)) {
            return $this->registry->get($entry);
        }

        if (!is_string($entry)) {
            return $entry;
        }

        return $this->container->get($entry);
    }

    /**
     * A filter entry as its {@see RouteFilter}.
     *
     * @throws UnexpectedValueException when it resolves to anything else — only possible for a
     *                                  container identifier, since a class name or an instance is
     *                                  checked when the route is declared
     */
    public function filter(mixed $entry): RouteFilter
    {
        // @mago-expect analysis:mixed-assignment
        $filter = $this->entry($entry);
        if (!$filter instanceof RouteFilter) {
            throw new UnexpectedValueException(sprintf(
                'Filter "%s" resolved to %s, which does not implement %s.',
                is_string($entry) ? $entry : get_debug_type($entry),
                get_debug_type($filter),
                RouteFilter::class,
            ));
        }

        return $filter;
    }
}
